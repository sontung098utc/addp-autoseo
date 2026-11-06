<?php

namespace App\Services\Crawler;

use DOMDocument;
use DOMElement;
use DOMXPath;

class HtmlSeoExtractor
{
    /**
     * Bóc tách toàn diện các chỉ số SEO On-page từ mã HTML
     *
     * @param string $html Mã HTML của trang
     * @param string $pageUrl URL trang đang kiểm tra
     * @param int $httpStatus Mã phản hồi HTTP (mặc định: 200)
     * @return array
     */
    public function extract(string $html, string $pageUrl, int $httpStatus = 200): array
    {
        $issues = [];
        $score = 100;

        if ($httpStatus !== 200) {
            $issues[] = "http_error_{$httpStatus}";
            return [
                'http_status' => $httpStatus,
                'title' => null,
                'meta_description' => null,
                'canonical_url' => null,
                'h1' => null,
                'h1_count' => 0,
                'headings_summary' => ['h1' => 0, 'h2' => 0, 'h3' => 0, 'h4' => 0],
                'images_count' => 0,
                'missing_alt_count' => 0,
                'internal_links_count' => 0,
                'external_links_count' => 0,
                'has_schema' => false,
                'schema_types' => [],
                'seo_score' => 0,
                'issues' => $issues,
            ];
        }

        // Khởi tạo DOMDocument an toàn với UTF-8
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        // Bổ sung meta UTF-8 để DOMDocument đọc đúng tiếng Việt
        $encodedHtml = '<?xml encoding="utf-8" ?>' . $html;
        @$dom->loadHTML($encodedHtml, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);

        // 1. Title Tag
        $titleNodes = $xpath->query('//title');
        $title = $titleNodes->length > 0 ? trim($titleNodes->item(0)->textContent) : null;
        $titleLength = $title ? mb_strlen($title, 'UTF-8') : 0;

        if (empty($title)) {
            $issues[] = 'missing_title';
            $score -= 20;
        } elseif ($titleLength < 25) {
            $issues[] = 'title_too_short';
            $score -= 5;
        } elseif ($titleLength > 70) {
            $issues[] = 'title_too_long';
            $score -= 5;
        }

        // 2. Meta Description
        $metaDescNodes = $xpath->query('//meta[translate(@name, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="description"]/@content');
        $metaDescription = $metaDescNodes->length > 0 ? trim($metaDescNodes->item(0)->textContent) : null;
        $descLength = $metaDescription ? mb_strlen($metaDescription, 'UTF-8') : 0;

        if (empty($metaDescription)) {
            $issues[] = 'missing_meta_description';
            $score -= 15;
        } elseif ($descLength < 70) {
            $issues[] = 'meta_description_too_short';
            $score -= 5;
        } elseif ($descLength > 165) {
            $issues[] = 'meta_description_too_long';
            $score -= 5;
        }

        // 3. Canonical URL
        $canonicalNodes = $xpath->query('//link[translate(@rel, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="canonical"]/@href');
        $canonicalUrl = $canonicalNodes->length > 0 ? trim($canonicalNodes->item(0)->textContent) : null;

        if (empty($canonicalUrl)) {
            $issues[] = 'missing_canonical_url';
            $score -= 10;
        }

        // 4. Heading Structure (H1 - H4)
        $h1Nodes = $xpath->query('//h1');
        $h1Count = $h1Nodes->length;
        $h1Text = $h1Count > 0 ? trim($h1Nodes->item(0)->textContent) : null;

        if ($h1Count === 0) {
            $issues[] = 'missing_h1';
            $score -= 15;
        } elseif ($h1Count > 1) {
            $issues[] = 'multiple_h1';
            $score -= 10;
        }

        $h2Count = $xpath->query('//h2')->length;
        $h3Count = $xpath->query('//h3')->length;
        $h4Count = $xpath->query('//h4')->length;

        if ($h2Count === 0) {
            $issues[] = 'missing_h2_subheadings';
            $score -= 5;
        }

        $headingsSummary = [
            'h1' => $h1Count,
            'h2' => $h2Count,
            'h3' => $h3Count,
            'h4' => $h4Count,
        ];

        // 5. Images & Alt Attributes Audit
        $imageNodes = $xpath->query('//img');
        $totalImages = $imageNodes->length;
        $missingAltCount = 0;

        /** @var DOMElement $imgNode */
        foreach ($imageNodes as $imgNode) {
            $alt = $imgNode->getAttribute('alt');
            if ($alt === '' || $alt === null) {
                $missingAltCount++;
            }
        }

        if ($missingAltCount > 0) {
            $issues[] = "missing_alt:{$missingAltCount}";
            $altPenalty = min(20, $missingAltCount * 4);
            $score -= $altPenalty;
        }

        // 6. Links Audit (Internal vs External)
        $parsedPage = parse_url($pageUrl);
        $pageHost = $parsedPage['host'] ?? '';

        $linkNodes = $xpath->query('//a/@href');
        $internalCount = 0;
        $externalCount = 0;

        foreach ($linkNodes as $linkNode) {
            $href = trim($linkNode->textContent);
            if (empty($href) || str_starts_with($href, '#') || str_starts_with($href, 'javascript:') || str_starts_with($href, 'mailto:') || str_starts_with($href, 'tel:')) {
                continue;
            }

            $parsedLink = parse_url($href);
            $linkHost = $parsedLink['host'] ?? '';

            if (empty($linkHost) || $linkHost === $pageHost || str_ends_with($linkHost, '.' . $pageHost)) {
                $internalCount++;
            } else {
                $externalCount++;
            }
        }

        // 7. Structured Data / Schema Markup (JSON-LD)
        $schemaNodes = $xpath->query('//script[@type="application/ld+json"]');
        $hasSchema = false;
        $schemaTypes = [];

        foreach ($schemaNodes as $node) {
            $jsonContent = trim($node->textContent);
            $decoded = json_decode($jsonContent, true);
            if (is_array($decoded)) {
                $hasSchema = true;
                if (isset($decoded['@type'])) {
                    $schemaTypes[] = is_array($decoded['@type']) ? implode(',', $decoded['@type']) : $decoded['@type'];
                } elseif (isset($decoded['@graph']) && is_array($decoded['@graph'])) {
                    foreach ($decoded['@graph'] as $graphItem) {
                        if (isset($graphItem['@type'])) {
                            $schemaTypes[] = is_array($graphItem['@type']) ? implode(',', $graphItem['@type']) : $graphItem['@type'];
                        }
                    }
                }
            }
        }

        $schemaTypes = array_values(array_unique($schemaTypes));
        if (! $hasSchema) {
            $issues[] = 'missing_json_ld_schema';
            $score -= 10;
        }

        $finalScore = max(0, min(100, $score));

        return [
            'http_status' => $httpStatus,
            'title' => $title,
            'title_length' => $titleLength,
            'meta_description' => $metaDescription,
            'meta_description_length' => $descLength,
            'canonical_url' => $canonicalUrl,
            'h1' => $h1Text,
            'h1_count' => $h1Count,
            'headings_summary' => $headingsSummary,
            'images_count' => $totalImages,
            'missing_alt_count' => $missingAltCount,
            'internal_links_count' => $internalCount,
            'external_links_count' => $externalCount,
            'has_schema' => $hasSchema,
            'schema_types' => $schemaTypes,
            'seo_score' => $finalScore,
            'issues' => $issues,
        ];
    }
}
