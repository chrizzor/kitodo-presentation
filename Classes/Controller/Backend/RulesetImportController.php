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

namespace Kitodo\Dlf\Controller\Backend;

use Kitodo\Dlf\Common\Helper;
use Kitodo\Dlf\Controller\AbstractController;
use Kitodo\Dlf\Domain\Repository\FormatRepository;
use Kitodo\Dlf\Domain\Repository\MetadataRepository;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Site\Entity\NullSite;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Controller for the backend module 'Ruleset Import'.
 *
 * Reads a Kitodo.Production ruleset XML file from the configured path and
 * allows activating or deactivating metadata records for Kitodo.Presentation
 * via a checkbox selection. Selected keys create new records or reactivate
 * hidden ones; deselected keys hide existing active records.
 *
 * @package TYPO3
 * @subpackage dlf
 *
 * @access public
 */
class RulesetImportController extends AbstractController
{
    /**
     * @access protected
     * @var int Current page id
     */
    protected int $pid;

    /**
     * @access protected
     * @var array Page info
     */
    protected array $pageInfo;

    /**
     * @access protected
     * @var array All configured site languages
     */
    protected array $siteLanguages;

    /**
     * @access protected
     * @var FormatRepository
     */
    protected FormatRepository $formatRepository;

    /**
     * @access public
     */
    public function injectFormatRepository(FormatRepository $formatRepository): void
    {
        $this->formatRepository = $formatRepository;
    }

    /**
     * @access protected
     * @var MetadataRepository
     */
    protected MetadataRepository $metadataRepository;

    /**
     * @access public
     */
    public function injectMetadataRepository(MetadataRepository $metadataRepository): void
    {
        $this->metadataRepository = $metadataRepository;
    }

    /**
     * Initialization for all actions.
     *
     * @access protected
     * @return void
     */
    protected function initializeAction(): void
    {
        $this->pid = (int) ($this->request->getQueryParams()['id'] ?? null);

        $frameworkConfiguration = $this->configurationManager->getConfiguration($this->configurationManager::CONFIGURATION_TYPE_FRAMEWORK);
        $frameworkConfiguration['persistence']['storagePid'] = $this->pid;
        $this->configurationManager->setConfiguration($frameworkConfiguration);

        try {
            $site = GeneralUtility::makeInstance(SiteFinder::class)->getSiteByPageId($this->pid);
        } catch (SiteNotFoundException $e) {
            $site = new NullSite();
        }
        $this->siteLanguages = $site->getLanguages();
    }

    /**
     * Render helper that creates the module template response.
     *
     * @access protected
     * @param string $template Template name relative to Backend/RulesetImport/
     * @param array $extraData Additional view data
     * @param bool $isError Render as error page
     * @return ResponseInterface
     */
    protected function templateResponse(string $template, array $extraData = [], bool $isError = false): ResponseInterface
    {
        $flashMessageService = GeneralUtility::makeInstance(FlashMessageService::class);
        $messageQueue = $flashMessageService->getMessageQueueByIdentifier();

        $moduleTemplateFactory = GeneralUtility::makeInstance(ModuleTemplateFactory::class);
        $moduleTemplate = $moduleTemplateFactory->create($this->request);
        $moduleTemplate->assignMultiple($this->viewData);
        $moduleTemplate->assignMultiple($extraData);
        $moduleTemplate->setFlashMessageQueue($messageQueue);

        $templateName = $isError ? 'Backend/RulesetImport/Error' : 'Backend/RulesetImport/' . $template;
        return $moduleTemplate->renderResponse($templateName);
    }

    /**
     * Main action: read the configured ruleset, enrich keys with DB state,
     * and render the checkbox selection.
     *
     * @access public
     * @return ResponseInterface
     */
    public function indexAction(): ResponseInterface
    {
        $this->pageInfo = BackendUtility::readPageAccess($this->pid, $GLOBALS['BE_USER']->getPagePermsClause(1)) ?: [];

        if (!isset($this->pageInfo['doktype']) || $this->pageInfo['doktype'] != 254) {
            return $this->templateResponse('Error', [], true);
        }

        $extConf = GeneralUtility::makeInstance(ExtensionConfiguration::class)->get('dlf');
        $rulesetPath = (string) ($extConf['rulesetImport']['rulesetPath'] ?? '');

        if (empty($rulesetPath)) {
            $this->addFlashMessage(
                $this->translate('rulesetImport.noRulesetPathMsg'),
                $this->translate('rulesetImport.noRulesetPath'),
                ContextualFeedbackSeverity::WARNING
            );
            return $this->templateResponse('Index', ['pid' => $this->pid, 'keys' => [], 'hasRulesetPath' => false]);
        }

        $absolutePath = GeneralUtility::getFileAbsFileName($rulesetPath);
        if (!is_file($absolutePath)) {
            $this->addFlashMessage(
                sprintf($this->translate('rulesetImport.rulesetFileNotFoundMsg'), $rulesetPath),
                $this->translate('rulesetImport.rulesetFileNotFound'),
                ContextualFeedbackSeverity::ERROR
            );
            return $this->templateResponse('Index', ['pid' => $this->pid, 'keys' => [], 'hasRulesetPath' => true, 'rulesetPath' => $rulesetPath]);
        }

        $xmlContent = file_get_contents($absolutePath);

        try {
            $keys = $this->parseRulesetXml($xmlContent);
        } catch (\Exception $e) {
            $this->addFlashMessage(
                $this->translate('rulesetImport.parseErrorMsg') . ' ' . $e->getMessage(),
                $this->translate('rulesetImport.parseError'),
                ContextualFeedbackSeverity::ERROR
            );
            
            return $this->templateResponse('Index', ['pid' => $this->pid, 'keys' => [], 'hasRulesetPath' => true, 'rulesetPath' => $rulesetPath]);
        }

        // Parse XSLT to derive MODS XPath expressions per metadata key
        $xsltPath = (string) ($extConf['rulesetImport']['xsltPath'] ?? '');
        $xpathPattern = (string) ($extConf['rulesetImport']['xpathPattern'] ?? "./mods:extension/kitodo:kitodo/kitodo:metadata[@name='%s']");
        $xsltXpaths = [];
        if (!empty($xsltPath)) {
            $xsltAbsolutePath = GeneralUtility::getFileAbsFileName($xsltPath);
            if (is_file($xsltAbsolutePath)) {
                $xsltXpaths = $this->parseXsltXpaths((string) file_get_contents($xsltAbsolutePath));
            }
        }

        // Query all metadata records on this page including hidden ones
        $existingRecords = $this->findAllMetadataOnPage();

        // Enrich each key with its current database status and derived XPath
        foreach ($keys as $keyId => &$keyData) {
            $indexName = preg_replace('/[^a-zA-Z0-9_]/', '_', $keyId);
            $existing = $existingRecords[$indexName] ?? null;
            if ($existing === null) {
                $keyData['dbStatus'] = 'new';
                $keyData['isChecked'] = false;
            } elseif ((int) $existing['hidden'] === 1) {
                $keyData['dbStatus'] = 'hidden';
                $keyData['isChecked'] = false;
            } else {
                $keyData['dbStatus'] = 'active';
                $keyData['isChecked'] = true;
            }

            if (isset($xsltXpaths[$keyId])) {
                $keyData['xpath'] = $xsltXpaths[$keyId];
                $keyData['xpathFromXslt'] = true;
            } else {
                $keyData['xpath'] = sprintf($xpathPattern, $keyId);
                $keyData['xpathFromXslt'] = false;
            }
        }
        unset($keyData);

        // Determine preferred label language from site configuration
        $preferredLang = 'de';
        if (!empty($this->siteLanguages)) {
            $langCode = $this->siteLanguages[0]->getLocale()->getLanguageCode();
            if (in_array($langCode, ['de', 'en'])) {
                $preferredLang = $langCode;
            }
        }

        return $this->templateResponse('Index', [
            'pid' => $this->pid,
            'keys' => $keys,
            'hasRulesetPath' => true,
            'rulesetPath' => $rulesetPath,
            'xsltPath' => $xsltPath,
            'preferredLang' => $preferredLang,
        ]);
    }

    /**
     * Save action: create new records for selected keys, unhide previously
     * hidden records, and hide active records that were deselected.
     *
     * @access public
     * @return ResponseInterface
     */
    public function saveAction(): ResponseInterface
    {
        $this->pageInfo = BackendUtility::readPageAccess($this->pid, $GLOBALS['BE_USER']->getPagePermsClause(1)) ?: [];

        if (!isset($this->pageInfo['doktype']) || $this->pageInfo['doktype'] != 254) {
            return $this->templateResponse('Error', [], true);
        }

        $postData = $this->request->getParsedBody();
        $selectedKeys = array_flip((array) ($postData['selectedKeys'] ?? []));
        $labelLang = (string) ($postData['labelLang'] ?? 'de');

        $extConf = GeneralUtility::makeInstance(ExtensionConfiguration::class)->get('dlf');
        $rulesetPath = (string) ($extConf['rulesetImport']['rulesetPath'] ?? '');
        $xpathPattern = (string) ($extConf['rulesetImport']['xpathPattern'] ?? "./mods:extension/kitodo:kitodo/kitodo:metadata[@name='%s']");
        $xsltPath = (string) ($extConf['rulesetImport']['xsltPath'] ?? '');
        $xsltXpaths = [];
        if (!empty($xsltPath)) {
            $xsltAbsolutePath = GeneralUtility::getFileAbsFileName($xsltPath);
            if (is_file($xsltAbsolutePath)) {
                $xsltXpaths = $this->parseXsltXpaths((string) file_get_contents($xsltAbsolutePath));
            }
        }

        if (empty($rulesetPath)) {
            $this->addFlashMessage(
                $this->translate('rulesetImport.noRulesetPathMsg'),
                $this->translate('rulesetImport.noRulesetPath'),
                ContextualFeedbackSeverity::ERROR
            );
            return $this->redirect('index');
        }

        $absolutePath = GeneralUtility::getFileAbsFileName($rulesetPath);
        $xmlContent = is_file($absolutePath) ? file_get_contents($absolutePath) : false;

        if ($xmlContent === false) {
            $this->addFlashMessage(
                sprintf($this->translate('rulesetImport.rulesetFileNotFoundMsg'), $rulesetPath),
                $this->translate('rulesetImport.rulesetFileNotFound'),
                ContextualFeedbackSeverity::ERROR
            );
            return $this->redirect('index');
        }

        $keys = $this->parseRulesetXml($xmlContent);

        $modsFormat = $this->formatRepository->findOneBy(['root' => 'mods']);
        if ($modsFormat === null) {
            $this->addFlashMessage(
                $this->translate('rulesetImport.noModsFormatMsg'),
                $this->translate('rulesetImport.noModsFormat'),
                ContextualFeedbackSeverity::ERROR
            );
            return $this->redirect('index');
        }

        $existingRecords = $this->findAllMetadataOnPage();
        $defaultWrap = BackendUtility::getTcaFieldConfiguration('tx_dlf_metadata', 'wrap')['default'];
        $sortingOffset = count($existingRecords) + 1;

        $data = [];

        foreach ($keys as $keyId => $keyData) {
            $indexName = preg_replace('/[^a-zA-Z0-9_]/', '_', $keyId);
            $existing = $existingRecords[$indexName] ?? null;
            $isSelected = isset($selectedKeys[$keyId]);

            if ($isSelected && $existing === null) {
                // Create new record
                $label = $keyData['labels'][$labelLang]
                    ?? $keyData['labels']['de']
                    ?? $keyData['labels']['en']
                    ?? $keyId;
                $xpath = $xsltXpaths[$keyId] ?? sprintf($xpathPattern, $keyId);

                $formatNewId = uniqid('NEW');
                $metadataNewId = uniqid('NEW');

                $data['tx_dlf_metadataformat'][$formatNewId] = [
                    'pid' => $this->pid,
                    'encoded' => $modsFormat->getUid(),
                    'xpath' => $xpath,
                    'xpath_sorting' => '',
                    'mandatory' => 0,
                ];

                $data['tx_dlf_metadata'][$metadataNewId] = [
                    'pid' => $this->pid,
                    'label' => $label,
                    'index_name' => $indexName,
                    'format' => $formatNewId,
                    'default_value' => '',
                    'wrap' => $defaultWrap,
                    'index_tokenized' => 0,
                    'index_stored' => 1,
                    'index_indexed' => 1,
                    'index_boost' => 1.0,
                    'is_sortable' => 0,
                    'is_facet' => 0,
                    'is_listed' => 1,
                    'index_autocomplete' => 0,
                    'sorting' => $sortingOffset++,
                    'hidden' => 0,
                ];
            } elseif ($isSelected && $existing !== null && (int) $existing['hidden'] === 1) {
                // Reactivate hidden record
                $data['tx_dlf_metadata'][(string) $existing['uid']] = ['hidden' => 0];
            } elseif (!$isSelected && $existing !== null && (int) $existing['hidden'] === 0) {
                // Hide active record
                $data['tx_dlf_metadata'][(string) $existing['uid']] = ['hidden' => 1];
            }
        }

        if (!empty($data)) {
            Helper::processDatabaseAsAdmin($data, [], true);
        }

        $this->addFlashMessage(
            $this->translate('rulesetImport.saveSuccessMsg'),
            $this->translate('rulesetImport.saveSuccess'),
            ContextualFeedbackSeverity::OK
        );
        return $this->redirect('index');
    }

    /**
     * Error action.
     *
     * @access public
     * @return ResponseInterface
     */
    public function errorAction(): ResponseInterface
    {
        return $this->templateResponse('Error', [], true);
    }

    /**
     * Query all tx_dlf_metadata records for the current pid, including hidden
     * ones. Returns a map indexed by index_name.
     *
     * @access private
     * @return array<string, array{uid: int, index_name: string, hidden: int}>
     */
    private function findAllMetadataOnPage(): array
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_dlf_metadata');

        $rows = $connection->select(
            ['uid', 'index_name', 'hidden'],
            'tx_dlf_metadata',
            ['pid' => $this->pid, 'deleted' => 0]
        )->fetchAllAssociative();

        $byIndexName = [];
        foreach ($rows as $row) {
            // Keep first occurrence if index_name is not unique
            if (!isset($byIndexName[$row['index_name']])) {
                $byIndexName[$row['index_name']] = $row;
            }
        }
        return $byIndexName;
    }

    /**
     * Parse a Kitodo.Production ruleset XML (v2 format) and extract all key elements.
     *
     * @access private
     * @param string $xmlContent The raw XML content
     * @return array<string, array{id: string, labels: array<string, string>, use: string}>
     * @throws \Exception if the XML cannot be parsed
     */
    private function parseRulesetXml(string $xmlContent): array
    {
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xmlContent);
        if ($xml === false) {
            $errors = libxml_get_errors();
            libxml_clear_errors();
            $errorMsg = implode('; ', array_map(fn($e) => trim($e->message), $errors));
            throw new \Exception($errorMsg ?: 'Unknown XML parse error');
        }

        $xml->registerXPathNamespace('rs', 'http://names.kitodo.org/ruleset/v2');

        $keys = [];

        $keyElements = $xml->xpath('//rs:declaration/rs:key');
        if ($keyElements === false || empty($keyElements)) {
            $keyElements = $xml->xpath('//declaration/key') ?: [];
        }

        foreach ($keyElements as $keyElement) {
            $this->extractKey($keyElement, $keys);
        }

        return $keys;
    }

    /**
     * Recursively extract a key element and its sub-keys.
     *
     * @access private
     * @param \SimpleXMLElement $keyElement
     * @param array &$keys Output array
     * @return void
     */
    private function extractKey(\SimpleXMLElement $keyElement, array &$keys): void
    {
        $attrs = $keyElement->attributes();
        $id = (string) ($attrs['id'] ?? '');
        $use = (string) ($attrs['use'] ?? '');

        if ($id === '') {
            return;
        }

        $labels = [];
        foreach ($keyElement->children('http://names.kitodo.org/ruleset/v2') as $child) {
            if ($child->getName() === 'label') {
                $childAttrs = $child->attributes();
                $lang = (string) ($childAttrs['lang'] ?? 'de');
                $labels[$lang] = (string) $child;
            }
        }

        if (empty($labels)) {
            foreach ($keyElement->children() as $child) {
                if ($child->getName() === 'label') {
                    $childAttrs = $child->attributes();
                    $lang = (string) ($childAttrs['lang'] ?? 'de');
                    $labels[$lang] = (string) $child;
                }
            }
        }

        if (empty($labels)) {
            $labels = ['de' => $id];
        }

        $keys[$id] = [
            'id' => $id,
            'labels' => $labels,
            'use' => $use,
        ];

        foreach ($keyElement->children('http://names.kitodo.org/ruleset/v2') as $child) {
            if ($child->getName() === 'key') {
                $this->extractKey($child, $keys);
            }
        }

        foreach ($keyElement->children() as $child) {
            if ($child->getName() === 'key') {
                $childAttrs = $child->attributes();
                $childId = (string) ($childAttrs['id'] ?? '');
                if ($childId !== '' && !isset($keys[$childId])) {
                    $this->extractKey($child, $keys);
                }
            }
        }
    }

    /**
     * Parse an export XSLT file to derive MODS XPath expressions for Kitodo
     * metadata keys. Returns a map of key name → MODS XPath relative to mods:mods.
     *
     * Two patterns are recognised within the XSLT:
     *  1. <xsl:variable select="kitodo:metadata[@name='X']"/> whose value is then
     *     referenced via <xsl:value-of select="$varName"/> or
     *     <xsl:for-each select="$varName"> inside an MODS element tree.
     *  2. <xsl:for-each select="kitodo:metadata[@name='X']"> that directly iterates
     *     kitodo metadata and outputs MODS content.
     *
     * @access private
     * @param string $xsltContent Raw XSLT source
     * @return array<string, string>  keyName → XPath string
     */
    private function parseXsltXpaths(string $xsltContent): array
    {
        libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $dom->loadXML($xsltContent);
        libxml_clear_errors();

        $xp = new \DOMXPath($dom);
        $xp->registerNamespace('xsl', 'http://www.w3.org/1999/XSL/Transform');
        $xp->registerNamespace('mods', 'http://www.loc.gov/mods/v3');
        $xp->registerNamespace('kitodo', 'http://meta.kitodo.org/v1/');

        // Build a map: variable name → kitodo key name
        // from all <xsl:variable select="kitodo:metadata[@name='X']">
        $varToKey = [];
        $variables = $xp->query("//xsl:variable[contains(@select, \"kitodo:metadata[@name=\")]");
        foreach ($variables as $varNode) {
            $varSelect = $varNode->getAttribute('select');
            if (preg_match("/kitodo:metadata\[@name='([^']+)'\]/", $varSelect, $m)) {
                $varToKey[$varNode->getAttribute('name')] = $m[1];
            }
        }

        $result = [];

        // Iterate over every xsl:value-of in the document.
        // For each one: derive the MODS XPath by walking up the DOM, then resolve
        // the kitodo key by walking up ancestors to find the for-each or variable
        // that provides the kitodo:metadata[@name='X'] context.
        $allValueOf = $xp->query('//xsl:value-of');
        foreach ($allValueOf as $valueOfNode) {
            $modsXpath = $this->buildModsXpathFromNode($valueOfNode);
            if ($modsXpath === null) {
                continue;
            }

            $keyName = $this->findKitodoKeyName($valueOfNode, $varToKey);
            if ($keyName === null) {
                continue;
            }

            // First match wins
            if (!isset($result[$keyName])) {
                $result[$keyName] = $modsXpath;
            }
        }

        return $result;
    }

    /**
     * Given an xsl:value-of node and the pre-built variable→key map, walk up the
     * ancestor axis to find the kitodo metadata key name that provides the value.
     *
     * Checks three sources (in order):
     *  1. The xsl:value-of's own select="$varName" where varName is in $varToKey
     *  2. An ancestor xsl:for-each with select="kitodo:metadata[@name='X']"
     *  3. An ancestor xsl:for-each with select="$varName" where varName is in $varToKey
     *
     * @access private
     * @param \DOMNode $node      The xsl:value-of element
     * @param array<string,string> $varToKey  variable name → kitodo key name
     * @return string|null
     */
    private function findKitodoKeyName(\DOMNode $node, array $varToKey): ?string
    {
        $xslNs = 'http://www.w3.org/1999/XSL/Transform';

        // Check whether the value-of itself references a variable that maps to a key
        if ($node instanceof \DOMElement) {
            $select = $node->getAttribute('select');
            if (preg_match('/^\$(\w+)$/', $select, $m) && isset($varToKey[$m[1]])) {
                return $varToKey[$m[1]];
            }
        }

        // Walk up ancestors looking for a for-each that provides kitodo context
        $current = $node->parentNode;
        while ($current !== null && !($current instanceof \DOMDocument)) {
            if ($current->namespaceURI === $xslNs && $current->localName === 'for-each') {
                $select = $current->getAttribute('select');
                // Direct iteration: for-each select="kitodo:metadata[@name='X']"
                if (preg_match("/kitodo:metadata\[@name='([^']+)'\]/", $select, $m)) {
                    return $m[1];
                }
                // Via variable: for-each select="$varName"
                if (preg_match('/^\$(\w+)$/', $select, $m) && isset($varToKey[$m[1]])) {
                    return $varToKey[$m[1]];
                }
            }
            $current = $current->parentNode;
        }

        return null;
    }

    /**
     * Walk the DOM upward from $node, collecting MODS namespace ancestor elements
     * (skipping XSL control elements), and return an XPath relative to mods:mods.
     *
     * Stops when mods:mods is reached or a non-MODS/non-XSL element is encountered.
     * Handles <xsl:element name="mods:xxx"> as a virtual MODS element.
     *
     * @access private
     * @param \DOMNode $node Starting node (e.g. xsl:value-of)
     * @return string|null  e.g. "./mods:titleInfo/mods:title", or null if not inside MODS
     */
    private function buildModsXpathFromNode(\DOMNode $node): ?string
    {
        $xslNs = 'http://www.w3.org/1999/XSL/Transform';
        $modsNs = 'http://www.loc.gov/mods/v3';

        $parts = [];
        $current = $node->parentNode;

        while ($current !== null && !($current instanceof \DOMDocument)) {
            $nsUri = $current->namespaceURI;
            $localName = $current->localName;

            // Stop condition: reached the mods:mods root element
            if ($nsUri === $modsNs && $localName === 'mods') {
                break;
            }

            // Handle <xsl:element name="mods:xxx"> — treated as a MODS element
            if ($nsUri === $xslNs && $localName === 'element') {
                $elementName = $current->getAttribute('name');
                if (str_starts_with($elementName, 'mods:')) {
                    $modsLocalName = substr($elementName, 5);
                    $predicate = $this->xslAttributePredicate($current);
                    array_unshift($parts, 'mods:' . $modsLocalName . $predicate);
                }
                $current = $current->parentNode;
                continue;
            }

            // Skip all other XSL control elements
            if ($nsUri === $xslNs) {
                $current = $current->parentNode;
                continue;
            }

            // MODS namespace element
            if ($nsUri === $modsNs) {
                $predicate = $this->modsAttributePredicate($current);
                array_unshift($parts, 'mods:' . $localName . $predicate);
                $current = $current->parentNode;
                continue;
            }

            // Any other namespace (mets:, etc.) — stop
            break;
        }

        if (empty($parts)) {
            return null;
        }

        return './' . implode('/', $parts);
    }

    /**
     * Build an XPath predicate string from the literal XML attributes of a MODS
     * element. Only static values (no template expressions) are included.
     *
     * @access private
     * @param \DOMElement $element
     * @return string  e.g. "[@eventType='publication']" or ""
     */
    private function modsAttributePredicate(\DOMElement $element): string
    {
        $predicates = [];
        foreach ($element->attributes as $attr) {
            $value = $attr->value;
            // Skip dynamic values that contain XPath/XSLT expressions
            if (str_contains($value, '{') || str_contains($value, '$')) {
                continue;
            }
            $predicates[] = '@' . $attr->localName . "='" . $value . "'";
        }
        return $predicates ? '[' . implode(' and ', $predicates) . ']' : '';
    }

    /**
     * Build an XPath predicate string from static <xsl:attribute> children of an
     * <xsl:element>. Children whose value contains a dynamic <xsl:value-of> are
     * skipped.
     *
     * @access private
     * @param \DOMElement $xslElement  An <xsl:element name="mods:xxx"> node
     * @return string  e.g. "[@type='code']" or ""
     */
    private function xslAttributePredicate(\DOMElement $xslElement): string
    {
        $xslNs = 'http://www.w3.org/1999/XSL/Transform';
        $predicates = [];

        foreach ($xslElement->childNodes as $child) {
            if (!($child instanceof \DOMElement)) {
                continue;
            }
            if ($child->namespaceURI !== $xslNs || $child->localName !== 'attribute') {
                continue;
            }
            $attrName = $child->getAttribute('name');
            // Check whether the attribute value is static (no xsl:value-of child)
            $isDynamic = false;
            foreach ($child->childNodes as $grandChild) {
                if ($grandChild instanceof \DOMElement && $grandChild->localName === 'value-of') {
                    $isDynamic = true;
                    break;
                }
            }
            if (!$isDynamic) {
                $attrValue = trim($child->textContent);
                if ($attrValue !== '') {
                    $predicates[] = '@' . $attrName . "='" . $attrValue . "'";
                }
            }
        }

        return $predicates ? '[' . implode(' and ', $predicates) . ']' : '';
    }

    /**
     * Translate a key from the module language file.
     *
     * @access private
     * @param string $key
     * @return string
     */
    private function translate(string $key): string
    {
        return \TYPO3\CMS\Extbase\Utility\LocalizationUtility::translate(
            $key,
            'Dlf',
            [],
            null,
            'locallang_mod_rulesetimport'
        ) ?? $key;
    }
}
