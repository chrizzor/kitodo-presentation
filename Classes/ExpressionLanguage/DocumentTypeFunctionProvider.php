<?php

/**
 * (c) Kitodo. Key to digital objects e.V. <contact@kitodo.org>
 *
 * This file is part of the Kitodo and TYPO3 projects.
 *
 * @license GNU General Public License version 3 or later.
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace Kitodo\Dlf\ExpressionLanguage;

use Kitodo\Dlf\Common\AbstractDocument;
use Kitodo\Dlf\Common\IiifManifest;
use Kitodo\Dlf\Domain\Model\Document;
use Kitodo\Dlf\Domain\Repository\DocumentRepository;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\ExpressionLanguage\ExpressionFunction;
use Symfony\Component\ExpressionLanguage\ExpressionFunctionProviderInterface;
use TYPO3\CMS\Core\ExpressionLanguage\RequestWrapper;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MathUtility;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManager;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;

/**
 * Provider class for additional "getDocumentType" function to the ExpressionLanguage.
 *
 * @package TYPO3
 * @subpackage dlf
 *
 * @access public
 */
class DocumentTypeFunctionProvider implements ExpressionFunctionProviderInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * @access public
     *
     * @return ExpressionFunction[] An array of Function instances
     */
    public function getFunctions(): array
    {
        return [
            $this->getDocumentTypeFunction(),
        ];
    }

    /**
     * This holds the current document
     *
     * @var Document|null
     * @access protected
     */
    protected ?Document $document;

    /**
     * @var ConfigurationManager
     */
    protected $configurationManager;

    public function injectConfigurationManager(ConfigurationManager $configurationManager): void
    {
        $this->configurationManager = $configurationManager;
    }

    /**
     * @var DocumentRepository
     */
    protected $documentRepository;
    
    /**
     * @param DocumentRepository $documentRepository
     */
    public function injectDocumentRepository(DocumentRepository $documentRepository): void
    {
        $this->documentRepository = $documentRepository;
    }
    /**
     * Initialize the extbase repositories
     *
     * @access protected
     *
     * @param int $storagePid The storage pid
     *
     * @return void
     */
    protected function initializeRepositories(int $storagePid): void
    {
        $frameworkConfiguration = $this->configurationManager->getConfiguration(ConfigurationManagerInterface::CONFIGURATION_TYPE_FRAMEWORK);

        $frameworkConfiguration['persistence']['storagePid'] = MathUtility::forceIntegerInRange((int) $storagePid, 0);
        $this->configurationManager->setConfiguration($frameworkConfiguration);

        $this->documentRepository = GeneralUtility::makeInstance(DocumentRepository::class);
    }

    /**
     * Shortcut function to access field values
     *
     * @access protected
     *
     * @return ExpressionFunction
     */
    protected function getDocumentTypeFunction(): ExpressionFunction
    {
        return new ExpressionFunction(
            'getDocumentType',
            function()
            {
                // Not implemented, we only use the evaluator
            },
            function($arguments, $cPid)
            {
                /** @var RequestWrapper $requestWrapper */
                $requestWrapper = $arguments['request'];
                $queryParams = $requestWrapper->getQueryParams();

                $type = 'undefined';

                // It happens that $queryParams is an empty array or does not contain a key 'tx_dlf'
                // in case of other contexts. In this case we have to return here to avoid log messages.
                if (empty($queryParams) || !isset($queryParams['tx_dlf'])) {
                    return $type;
                }

                // Load document with current plugin parameters.
                $this->loadDocument($queryParams['tx_dlf'], $cPid);
                if ($this->document === null || $this->document->getCurrentDocument() === null) {
                    return $type;
                }
                // Set PID for metadata definitions.
                $this->document->getCurrentDocument()->cPid = $cPid;

                $metadata = $this->document->getCurrentDocument()->getTitledata($cPid);
                if (!empty($metadata['type'][0])) {
                    // Calendar plugin does not support IIIF (yet). Abort for all newspaper related types.
                    if (
                        $this->document->getCurrentDocument() instanceof IiifManifest
                        && array_search($metadata['type'][0], ['newspaper', 'ephemera', 'year', 'issue']) !== false
                    ) {
                        return $type;
                    }
                    $type = $metadata['type'][0];
                }
                return $type;
            });
    }

    /**
     * Loads the current document into $this->document
     *
     * @access protected
     *
     * @param array $requestData The request data
     * @param int $pid Storage Pid
     *
     * @return void
     */
    protected function loadDocument(array $requestData, int $pid): void
    {
        // Try to get document format from database
        if (!empty($requestData['id'])) {

            $this->initializeRepositories($pid);

            $doc = null;
            if (MathUtility::canBeInterpretedAsInteger($requestData['id'])) {
                // find document from repository by uid
                $this->document = $this->documentRepository->findOneByIdAndSettings((int) $requestData['id'], ['storagePid' => $pid]);
                if ($this->document) {
                    $doc = AbstractDocument::getInstance($this->document->getLocation(), ['storagePid' => $pid], true);
                } else {
                    $this->logger->error('Invalid UID "' . $requestData['id'] . '" or PID "' . $pid . '" for document loading');
                }
            } else if (GeneralUtility::isValidUrl($requestData['id'])) {

                $doc = AbstractDocument::getInstance($requestData['id'], ['storagePid' => $pid], true);

                if ($doc !== null) {
                    if ($doc->recordId) {
                        $this->document = $this->documentRepository->findOneByRecordId($doc->recordId);
                    }

                    if ($this->document === null) {
                        // create new dummy Document object
                        $this->document = GeneralUtility::makeInstance(Document::class);
                    }

                    $this->document->setLocation($requestData['id']);
                } else {
                    $this->logger->error('Invalid location given "' . $requestData['id'] . '" for document loading');
                }
            }

            if ($this->document !== null && $doc !== null) {
                $this->document->setCurrentDocument($doc);
            }

        } elseif (!empty($requestData['recordId'])) {

            $this->document = $this->documentRepository->findOneByRecordId($requestData['recordId']);

            if ($this->document !== null) {
                $doc = AbstractDocument::getInstance($this->document->getLocation(), ['storagePid' => $pid], true);
                if ($doc !== null) {
                    $this->document->setCurrentDocument($doc);
                } else {
                    $this->logger->error('Failed to load document with record ID "' . $requestData['recordId'] . '"');
                }
            }
        } else {
            $this->logger->error('Empty UID or invalid PID "' . $pid . '" for document loading');
        }
    }
}
