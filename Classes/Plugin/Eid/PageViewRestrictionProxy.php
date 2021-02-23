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

namespace Kitodo\Dlf\Plugin\Eid;

use Kitodo\Dlf\Common\Document;
use Kitodo\Dlf\Common\Helper;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Utility\GeneralUtility;
/**
 * eID image proxy for plugin 'Page View' of the 'dlf' extension
 *
 * @author Alexander Bigga <alexander.bigga@slub-dresden.de>
 * @package TYPO3
 * @subpackage dlf
 * @access public
 */
class PageViewRestrictionProxy
{

    /**
     * The main method of the eID script
     *
     * @access public
     *
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     */
    public function main(ServerRequestInterface $request)
    {
        // header parameter for getUrl(); allowed values 0,1,2; default 0
        $header = (int) $request->getQueryParams()['header'];
        $header = \TYPO3\CMS\Core\Utility\MathUtility::forceIntegerInRange($header, 0, 2, 0);

        // the URI to fetch data or header from
        $url = (string) $request->getQueryParams()['url'];
        if (!GeneralUtility::isValidUrl($url)) {
            throw new \InvalidArgumentException('No valid url passed!', 1580482805);
        }

        $page = (int) $request->getQueryParams()['page'];
        $docId = (int) $request->getQueryParams()['id'];
        $fileGrp = (string) $request->getQueryParams()['fileGrp'];

        if ($docId) {
            $this->doc = Document::getInstance($docId);
            if (!$this->doc->ready) {
                // Destroy the incomplete object.
                $this->doc = null;
                Helper::devLog('Failed to load document with UID ' . $this->piVars['id'], DEVLOG_SEVERITY_ERROR);
            }

            if ($page == 0) {
                if ($this->doc->thumbnailLoaded) {
                    $restriction = '';
                    $restrictionGroup = '';
                }
            } else {
                $fileLocationFromMets = $this->doc->getFileLocation($this->doc->physicalStructureInfo[$this->doc->physicalStructure[$page]]['files'][$fileGrp]);
                $restriction = $this->doc->getFileRestriction($this->doc->physicalStructureInfo[$this->doc->physicalStructure[$page]]['dmdId']);

                $restrictionGroup = $this->doc->getDocumentRestrictionGroup();

                // check if struct element is "restricted"
                $logicalStructId = $this->doc->smLinks['p2l'][$this->doc->physicalStructureInfo[$this->doc->physicalStructure[$page]]['id']][1];
                $restrictionStructElement = $this->doc->getFileRestriction($this->doc->getLogicalStructure($logicalStructId)['dmdId']);

                $urlValidationResponse = $this->checkUrl($fileLocationFromMets, $url);
                if ($urlValidationResponse) {
                    // error / manipulation
                    return $urlValidationResponse;
                }
            }

            /** @var \TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController $typoScriptFrontendController */
            $typoScriptFrontendController = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(
                \TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController::class,
                $GLOBALS['TYPO3_CONF_VARS'],
                1, // page ID
                0 // pageType.
            );
            $typoScriptFrontendController->initFEuser();
            $typoScriptFrontendController->initUserGroups();

            if (($restriction === "restricted" || $restrictionStructElement === "restricted") && $typoScriptFrontendController->fe_user->user['username'] != '' &&
                    ($typoScriptFrontendController->fe_user->groupData['title'][1] == 'AdminGroup' ||
                        array_slice($typoScriptFrontendController->fe_user->groupData['title'], 0, 1)[0] == $restrictionGroup)
            ) {
                // fetch the requested data or header
                $fetchedData = GeneralUtility::getUrl($url, $header);
            } else if ($restriction !== "restricted" && $restrictionStructElement !== "restricted") {
                $fetchedData = GeneralUtility::getUrl($url, $header);
            } else {
                $fetchedData = GeneralUtility::getUrl('http://kitodoMOB:frontendMOB@167.86.98.211/fileadmin/placeholder.png', $header);
            }
        } else {
            //missing doc id return placeholder
            $fetchedData = GeneralUtility::getUrl('http://kitodoMOB:frontendMOB@167.86.98.211/fileadmin/placeholder.png', $header);
        }

        // create response object
        /** @var Response $response */
        $response = GeneralUtility::makeInstance(Response::class);
        if ($fetchedData) {
            $response->getBody()->write($fetchedData);
            $response = $response->withHeader('Access-Control-Allow-Methods', 'GET');
            $response = $response->withHeader('Access-Control-Allow-Origin', $request->getHeaderLine('Origin') ?: '*');
            $response = $response->withHeader('Access-Control-Max-Age', '86400');
            $response = $response->withHeader('Content-Type', finfo_buffer(finfo_open(FILEINFO_MIME), $fetchedData));
        }

        return $response;


    }

    protected function checkUrl($metsUrl, $url) {
        $params = explode("&", $metsUrl);
        $urlPageValid = false;
        foreach ($params as $key => $value) {
            if (urldecode(str_replace('url=', '', $value)) == $url) {
                $urlPageValid = true;
            }
        }
        if (!$urlPageValid) {
            /** @var Response $response */
            $response = GeneralUtility::makeInstance(Response::class);
            $fetchedData = '{"error": "Image or page not valid"}';
            if ($fetchedData) {
                $response->getBody()->write($fetchedData);
            }
            return $response;
        } else {
            return false;
        }
    }
}