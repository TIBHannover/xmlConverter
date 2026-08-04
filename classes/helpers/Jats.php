<?php

/**
 * @file plugins/generic/xmlConverter/classes/Jats.php
 *
 * Copyright (c) 2014-2025 Simon Fraser University
 * Copyright (c) 2003-2026 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @class Jats
 *
 * @ingroup plugins_generic_xmlconverter
 *
 * @brief Helper class for JATS.
 */

namespace APP\plugins\generic\xmlConverter\classes\helpers;

use APP\facades\Repo;
use APP\publication\Publication;
use APP\submission\Submission;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Exception;
use PKP\context\Context;
use PKP\decision\Decision;
use PKP\facades\Locale;

class Jats extends DOMDocument
{
    /**
     * Updates a given DOMDocument with journal metadata.
     */
    public static function updateJournalMeta(DOMDocument $origDocument, $context): void
    {
        $xpath = new DOMXpath($origDocument);

        $journalMeta = $xpath->query("//article/front/journal-meta");
        foreach ($journalMeta as $journalMetaEntry) {
            try {
                $origDocument->documentElement->removeChild($journalMetaEntry);
            } catch (Exception $e) {
                error_log($e->getMessage());
            }
        }
        $articleMeta = $xpath->query("//article/front/article-meta");
        if (count($articleMeta) == 1) {
            if (count($journalMeta) == 0) {
                $journalMeta = $origDocument->createElement('journal-meta');

                $journalIdType = $origDocument->createElement('journal-id', $context->getLocalizedAcronym());
                $journalIdType->setAttribute('journal-id-type', 'publisher-id');
                $journalMeta->appendChild($journalIdType);
                $issn = $origDocument->createElement('issn', $context->getData('onlineIssn'));
                $issn->setAttribute('pub-type', 'epub');
                $journalMeta->appendChild($issn);
                $publisher = $origDocument->createElement('publisher');
                $publisherName = $origDocument->createElement('publisher-name', $context->getData('publisherInstitution'));
                $publisher->appendChild($publisherName);
                $journalMeta->appendChild($publisher);

                $articleMeta->item(0)->parentNode->insertBefore($journalMeta, $articleMeta->item(0));
            }
        }
    }

    /**
     * Updates a given DOMDocument with the article submission status history.
     */
    public static function updateArticleMetaHistory(
        DOMDocument $origDocument, Submission $submission, string $datePublished = null): void
    {
        $xpath = new DOMXpath($origDocument);

        $history = $xpath->query("//article/front/article-meta/history");

        foreach ($history as $item) {
            $item->parentNode->removeChild($item);
        }
        $history = $origDocument->createElement('history');
        $dateReceived = $submission->getData('dateSubmitted');
        if ($dateReceived) {
            $dateReceived = self::getDate($origDocument, $dateReceived, 'received');
            $history->appendChild($dateReceived);
        }

        $decisions = Repo::decision()->getCollector()
            ->filterBySubmissionIds([$submission->getId()])
            ->getMany();
        $dateAccepted = self::selectAcceptedDate($decisions);
        if ($dateAccepted) {
            $history->appendChild(self::getDate($origDocument, $dateAccepted, 'accepted'));
        }

        if ($datePublished) {
            $history->appendChild(self::getDate($origDocument, $datePublished, 'published'));
        }

        $articleMeta = $xpath->query("//article/front/article-meta");
        if ($articleMeta) {
            $articleMeta->item(0)->appendChild($history);
        }
    }

    /**
     * Updates a given DOMDocument by adding or modifying publication date metadata.
     */
    public static function updateJournalMetaPubDate(
        DOMDocument $origDocument, Context $context, Submission $submission,
        string      $datePublished, string $firstPage = null, string $lastPage = null): void
    {
        $xpath = new DOMXpath($origDocument);
        $timestamp = strtotime($datePublished);

        $pubDate = $xpath->query("//article/front/article-meta/pub-date");

        foreach ($pubDate as $item) {
            $item->parentNode->removeChild($item);
        }

        $pubDate = $origDocument->createElement('pub-date');
        $pubDate->setAttribute('pub-type', 'epub');
        $day = $origDocument->createElement('day', date('d', $timestamp));
        $pubDate->appendChild($day);
        $month = $origDocument->createElement('month', date('m', $timestamp));
        $pubDate->appendChild($month);
        $year = $origDocument->createElement('year', date('Y', $timestamp));
        $pubDate->appendChild($year);

        $issue = Repo::issue()->getBySubmissionId($submission->getId(), $context->getId());
        if ($issue) {
            $volume = $origDocument->createElement('volume', $issue->getVolume());
            $pubDate->appendChild($volume);
        }

        if ($firstPage) {
            $pubDate->appendChild($origDocument->createElement('fpage', $firstPage));
        }
        if ($lastPage) {
            $pubDate->appendChild($origDocument->createElement('lpage', $lastPage));
        }
        $articleMeta = $xpath->query("//article/front/article-meta");
        if ($articleMeta) {
            $articleMeta->item(0)->appendChild($pubDate);
        }
    }

    /**
     * Updates a given DOMDocument with the permissions and licensing information.
     */
    public static function updateArticleMetaCCBYLicense(DOMDocument $origDocument, $context, int $copyrightYear = null, string $licenseUrlOverride = null): void
    {
        $xpath = new DOMXpath($origDocument);
        $permissions = $xpath->query("//article/front/article-meta/permissions");

        foreach ($permissions as $permission) {
            if ($permission->parentNode) {
                $permission->parentNode->removeChild($permission);
            }
        }

        $articleMeta = $xpath->query("//article/front/article-meta");
        $licenseUrl = trim((string)$licenseUrlOverride) ?: $context->getData('licenseUrl');
        if (count($articleMeta) > 0 and $licenseUrl) {
            preg_match('/http[s]?:(www\.)?\/\/creativecommons.org\/licenses\/([a-z]+(-[a-z]+)*)\/(\d.0)\/*([a-z]*).*/i', $licenseUrl, $matches);
            if (count($matches) > 5 and $matches[2] and $matches[4]) {
                if (!$copyrightYear) {
                    $copyrightYear = date('Y');
                }

                $permissionNode = $origDocument->createElement('permissions');
                $copyrightStatementNode = $origDocument->createElement('copyright-statement', '© ' . $copyrightYear . ' The Author(s)');
                $permissionNode->appendChild($copyrightStatementNode);
                $copyrightYearNode = $origDocument->createElement('copyright-year', $copyrightYear);
                $permissionNode->appendChild($copyrightYearNode);

                $copyrightLicenseNode = $origDocument->createElement('license');
                $copyrightLicenseNode->setAttribute('license-type', 'open-access');
                $copyrightLicenseNode->setAttribute('xlink:href', $licenseUrl);
                $copyrightLicenseNode->setAttribute('xml:lang', 'en');

                $copyrightLicensePNode = $origDocument->createElement('license-p');

                $inlineGraphicNode = $origDocument->createElement('inline-graphic');
                $inlineGraphicNode->setAttribute('xlink:href', 'https://mirrors.creativecommons.org/presskit/buttons/88x31/svg/' . $matches[2] . '.svg');
                $copyrightLicensePNode->appendChild($inlineGraphicNode);

                $countryCode = $matches[5] ? strtoupper($matches[5]) : '';
                $isoCodes = new \Sokil\IsoCodes\IsoCodesFactory();
                $country = $isoCodes->getCountries()->getByAlpha2($countryCode) ? $isoCodes->getCountries()->getByAlpha2($countryCode)->getName() : '';
                $licensePTextNode = $origDocument->createTextNode("This work is published under the Creative Commons  {$country} License {$matches[4]} (CC BY {$matches[4]} {$countryCode}).");

                $copyrightLicensePNode->appendChild($licensePTextNode);
                $copyrightLicenseNode->appendChild($copyrightLicensePNode);
                $permissionNode->appendChild($copyrightLicenseNode);
                $articleMeta[0]->appendChild($permissionNode);
            }
        }
    }

    /**
     * Selects the most recent accepted date from a set of editorial decisions.
     */
    public static function selectAcceptedDate(iterable $decisions): ?string
    {
        $acceptStages = [WORKFLOW_STAGE_ID_EXTERNAL_REVIEW];

        $dateAccepted = null;
        foreach ($decisions as $decision) {
            if ($decision->getData('decision') == Decision::ACCEPT
                && in_array($decision->getData('stageId'), $acceptStages)
                && !empty($decision->getData('dateDecided'))) {
                if (!$dateAccepted || strtotime($decision->getData('dateDecided')) > strtotime($dateAccepted)) {
                    $dateAccepted = $decision->getData('dateDecided');
                }
            }
        }

        return $dateAccepted;
    }

    /**
     * Updates a given DOMDocument with the article title.
     */
    public static function updateArticleTitle(DOMDocument $origDocument, Publication $publication): void
    {
        $title = trim((string)$publication->getLocalizedTitle());
        if ($title === '') {
            return;
        }

        $xpath = new DOMXpath($origDocument);
        $articleMeta = $xpath->query('//article/front/article-meta')->item(0);
        if (!$articleMeta) {
            return;
        }

        foreach ($xpath->query('./title-group', $articleMeta) ?: [] as $node) {
            $articleMeta->removeChild($node);
        }

        $titleGroup = $origDocument->createElement('title-group');
        $titleGroup->appendChild($origDocument->createElement('article-title', htmlspecialchars($title, ENT_XML1)));

        $contribGroup = $xpath->query('./contrib-group', $articleMeta)->item(0);
        if ($contribGroup) {
            $articleMeta->insertBefore($titleGroup, $contribGroup);
        } else {
            $articleMeta->appendChild($titleGroup);
        }
    }

    /**
     * Updates a given DOMDocument with the contributor group and affiliations.
     */
    public static function updateContribGroup(DOMDocument $origDocument, Publication $publication): void
    {
        $xpath = new DOMXpath($origDocument);
        $articleMeta = $xpath->query('//article/front/article-meta')->item(0);
        if (!$articleMeta) {
            return;
        }

        foreach ($xpath->query('./contrib-group | ./aff', $articleMeta) ?: [] as $node) {
            $articleMeta->removeChild($node);
        }

        $authors = $publication->getData('authors');
        if (!$authors || (is_countable($authors) && count($authors) === 0)) {
            return;
        }

        $locale = $publication->getData('locale') ?: Locale::getLocale();
        $contribGroup = $origDocument->createElement('contrib-group');
        $contribGroup->setAttribute('content-type', 'author');

        $affiliations = [];
        $affiliationIndex = 1;
        foreach ($authors as $author) {
            $given = (string)$author->getLocalizedGivenName($locale);
            $family = (string)$author->getLocalizedFamilyName($locale);
            $email = (string)$author->getEmail();
            $orcid = (string)$author->getOrcid();
            $affiliation = (string)$author->getLocalizedAffiliationNamesAsString($locale);

            $contrib = $origDocument->createElement('contrib');
            $contrib->setAttribute('contrib-type', 'person');
            $name = $origDocument->createElement('name');
            if ($family !== '') {
                $name->appendChild($origDocument->createElement('surname', htmlspecialchars($family, ENT_XML1)));
            }
            if ($given !== '') {
                $name->appendChild($origDocument->createElement('given-names', htmlspecialchars($given, ENT_XML1)));
            }
            $contrib->appendChild($name);
            if ($email !== '') {
                $contrib->appendChild($origDocument->createElement('email', htmlspecialchars($email, ENT_XML1)));
            }
            if ($orcid !== '') {
                $contribId = $origDocument->createElement('contrib-id', htmlspecialchars($orcid, ENT_XML1));
                $contribId->setAttribute('contrib-id-type', 'orcid');
                $contrib->appendChild($contribId);
            }
            if ($affiliation !== '') {
                if (!isset($affiliations[$affiliation])) {
                    $affiliations[$affiliation] = 'aff-' . $affiliationIndex++;
                }
                $xref = $origDocument->createElement('xref');
                $xref->setAttribute('ref-type', 'aff');
                $xref->setAttribute('rid', $affiliations[$affiliation]);
                $contrib->appendChild($xref);
            }
            $contribGroup->appendChild($contrib);
        }
        $articleMeta->appendChild($contribGroup);

        foreach ($affiliations as $text => $id) {
            $aff = $origDocument->createElement('aff');
            $aff->setAttribute('id', $id);
            $aff->appendChild($origDocument->createElement('institution', htmlspecialchars($text, ENT_XML1)));
            $articleMeta->appendChild($aff);
        }
    }

    /**
     * Creates a DOMElement representing a date, including its breakdown into day, month, and year.
     */
    public static function getDate(DOMDocument $origDocument, string $dateAndTime, string $type = null): DOMElement
    {
        $timestamp = strtotime($dateAndTime);
        $date = $origDocument->createElement('date');
        if ($type) {
            $date->setAttribute('type', $type);
        }
        $date->setAttribute('iso-8601-date', date('Y-m-d', $timestamp));
        $day = $origDocument->createElement('day', date('d', $timestamp));
        $date->appendChild($day);
        $month = $origDocument->createElement('month', date('m', $timestamp));
        $date->appendChild($month);
        $year = $origDocument->createElement('year', date('Y', $timestamp));
        $date->appendChild($year);

        return $date;
    }
}
