<?php
namespace TYPO3\Neos\Domain\Service;

/*                                                                        *
 * This script belongs to the TYPO3 Flow package "TYPO3.Neos".            *
 *                                                                        *
 * It is free software; you can redistribute it and/or modify it under    *
 * the terms of the GNU General Public License, either version 3 of the   *
 * License, or (at your option) any later version.                        *
 *                                                                        *
 * The TYPO3 project - inspiring people to share!                         *
 *                                                                        */

use TYPO3\Flow\Annotations as Flow;
use TYPO3\Flow\Utility\Files;
use TYPO3\TYPO3CR\Domain\Model\NodeInterface;

/**
 * The Site Export Service
 *
 * @Flow\Scope("prototype")
 */
class SiteExportService {

	/**
	 * @Flow\Inject
	 * @var \TYPO3\TYPO3CR\Domain\Service\ContextFactoryInterface
	 */
	protected $contextFactory;

	/**
	 * @var string
	 */
	protected $resourcesPath;

	/**
	 * Fetches the site with the given name and exports it into XML.
	 *
	 * @param array $sites
	 * @param \TYPO3\Neos\Domain\Service\ContentContext $contentContext
	 * @param boolean $tidy Whether to export formatted XML
	 * @param string $output Path or URI where the export output should be sent to
	 * @return void
	 */
	public function export(array $sites, \TYPO3\Neos\Domain\Service\ContentContext $contentContext, $tidy = FALSE, $output = 'php://stdout') {
		if ($output !== 'php://stdout' && $output !== 'php://output') {
			$this->resourcesPath = dirname($output) . '/Resources';
			Files::createDirectoryRecursively($this->resourcesPath);
		}

		$xmlWriter = new \XMLWriter();
		$xmlWriter->openUri($output);
		if ($tidy) {
			$xmlWriter->setIndent(TRUE);
		}
		$xmlWriter->startDocument('1.0', 'UTF-8');
		$xmlWriter->startElement('root');

		foreach ($sites as $site) {
			$xmlWriter->startElement('site');

				// site attributes
			$xmlWriter->writeAttribute('nodeName', $site->getNodeName());

				// site properties
			$xmlWriter->startElement('properties');
			$xmlWriter->writeElement('name', $site->getName());
			$xmlWriter->writeElement('state', $site->getState());
			$xmlWriter->writeElement('siteResourcesPackageKey', $site->getSiteResourcesPackageKey());
			$xmlWriter->endElement();

			$contextProperties = $contentContext->getProperties();
			$contextProperties['currentSite'] = $site;
			$contentContext = $this->contextFactory->create($contextProperties);

				// on to the nodes...
			$node = $contentContext->getCurrentSiteNode();

			foreach ($node->getChildNodes() as $childNode) {
				$this->exportNode($childNode, $xmlWriter);
			}

			$xmlWriter->endElement();
		}
		$xmlWriter->endElement();
		$xmlWriter->endDocument();

		$xmlWriter->flush();
	}

	/**
	 * Export a single node.
	 *
	 * @param NodeInterface $node
	 * @param \XMLWriter $xmlWriter
	 * @return void
	 */
	protected function exportNode(NodeInterface $node, \XMLWriter $xmlWriter) {
		$xmlWriter->startElement('node');

			// node attributes
		$xmlWriter->writeAttribute('identifier', $node->getIdentifier());
		$xmlWriter->writeAttribute('type', $node->getNodeType()->getName());
		$xmlWriter->writeAttribute('nodeName', $node->getName());
		$xmlWriter->writeAttribute('locale', '');
		if ($node->isHidden() === TRUE) {
			$xmlWriter->writeAttribute('hidden', 'true');
		}
		if ($node->isHiddenInIndex() === TRUE) {
			$xmlWriter->writeAttribute('hiddenInIndex', 'true');
		}
		$hiddenBeforeDateTime = $node->getHiddenBeforeDateTime();
		if ($hiddenBeforeDateTime !== NULL) {
			$xmlWriter->writeAttribute('hiddenBeforeDateTime', $hiddenBeforeDateTime->format(\DateTime::W3C));
		}
		$hiddenAfterDateTime = $node->getHiddenAfterDateTime();
		if ($hiddenAfterDateTime !== NULL) {
			$xmlWriter->writeAttribute('hiddenAfterDateTime', $hiddenAfterDateTime->format(\DateTime::W3C));
		}

			// access roles
		$accessRoles = $node->getAccessRoles();
		if (count($accessRoles) > 0) {
			$xmlWriter->startElement('accessRoles');
			foreach ($accessRoles as $role) {
				$xmlWriter->writeElement('role', $role);
			}
			$xmlWriter->endElement();
		}

			// node properties
		$nodeType = $node->getNodeType();
		$properties = $node->getProperties(TRUE);
		if (count($properties) > 0) {
			$xmlWriter->startElement('properties');
			foreach ($properties as $propertyName => $propertyValue) {
				$propertyType = $nodeType->getPropertyType($propertyName);
				switch ($propertyType) {
					case 'reference':
						$xmlWriter->startElement($propertyName);
						$xmlWriter->writeAttribute('__type', 'reference');
						if (!empty($propertyValue)) {
							$xmlWriter->startElement('node');
							$xmlWriter->writeAttribute('identifier', $propertyValue);
							$xmlWriter->endElement();
						}
						$xmlWriter->endElement();
					break;
					case 'references':
						$xmlWriter->startElement($propertyName);
						$xmlWriter->writeAttribute('__type', 'references');
						if (is_array($propertyValue)) {
							foreach ($propertyValue as $referencedTargetNode) {
								$xmlWriter->startElement('node');
								$xmlWriter->writeAttribute('identifier', $referencedTargetNode);
								$xmlWriter->endElement();
							}
						}
						$xmlWriter->endElement();
					break;
					default:
						if (is_object($propertyValue)) {
							$xmlWriter->startElement($propertyName);
							$xmlWriter->writeAttribute('__type', 'object');
							$xmlWriter->writeAttribute('__classname', get_class($propertyValue));
							$this->objectToXml($propertyValue, $xmlWriter);
							$xmlWriter->endElement();
						} elseif (strpos($propertyValue, '<') !== FALSE || strpos($propertyValue, '>') !== FALSE || strpos($propertyValue, '&') !== FALSE) {
							$xmlWriter->startElement($propertyName);
							if (strpos($propertyValue, '<![CDATA[') !== FALSE) {
								$xmlWriter->writeCdata(str_replace(']]>', ']]]]><![CDATA[>', $propertyValue));
							} else {
								$xmlWriter->writeCdata($propertyValue);
							}
							$xmlWriter->endElement();
						} else {
							$xmlWriter->writeElement($propertyName, $propertyValue);
						}
					break;
				}
			}
			$xmlWriter->endElement();
		}

			// and the child nodes recursively
		foreach ($node->getChildNodes() as $childNode) {
			$this->exportNode($childNode, $xmlWriter);
		}

		$xmlWriter->endElement();
	}

	/**
	 * Handles conversion of objects into a string format that can be exported in our
	 * XML format.
	 *
	 * Note: currently only ImageVariant instances are supported.
	 *
	 * @param object $object
	 * @param \XMLWriter $xmlWriter
	 * @return void
	 * @throws \TYPO3\Neos\Domain\Exception
	 */
	protected function objectToXml($object, \XMLWriter $xmlWriter) {
		$className = get_class($object);
		switch ($className) {
			case 'TYPO3\Media\Domain\Model\ImageVariant':
				/** @var \TYPO3\Media\Domain\Model\ImageVariant $object */
				$xmlWriter->startElement('processingInstructions');
				$xmlWriter->writeCdata(serialize($object->getProcessingInstructions()));
				$xmlWriter->endElement();

				$xmlWriter->startElement('originalImage');
				$xmlWriter->writeAttribute('__type', 'object');
				$xmlWriter->writeAttribute('__classname', '\TYPO3\Media\Domain\Model\Image');

				$xmlWriter->startElement('resource');
				$xmlWriter->writeAttribute('__type', 'object');
				$xmlWriter->writeAttribute('__classname', '\TYPO3\Flow\Resource\Resource');
				$resource = $object->getOriginalImage()->getResource();
				$xmlWriter->writeElement('filename', $resource->getFilename());
				if ($this->resourcesPath === NULL) {
					$xmlWriter->writeElement('content', base64_encode(file_get_contents($resource->getUri())));
				} else {
					$hash = $resource->getResourcePointer()->getHash();
					copy($resource->getUri(), $this->resourcesPath . '/'  . $hash);
					$xmlWriter->writeElement('hash', $hash);
				}
				$xmlWriter->endElement();

				$xmlWriter->endElement();
			break;
			case 'DateTime':
				$xmlWriter->writeElement('dateTime', $object->format(\DateTime::W3C));
			break;
			default:
				throw new \TYPO3\Neos\Domain\Exception('Unsupported object of type "' . get_class($object) . '" hit during XML export.', 1347144928);
		}
	}
}