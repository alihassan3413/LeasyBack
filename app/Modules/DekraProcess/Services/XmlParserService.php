<?php

namespace App\Modules\DekraProcess\Services;

use Carbon\Carbon;
use SimpleXMLElement;

class XmlParserService
{
    /**
     * Parse incoming DEKRA Quittung (confirmation) XML.
     *
     * @param  string  $xmlContent  Raw XML string
     * @return array Parsed confirmation data
     *
     * @throws \Exception
     */
    public function parseQuittung(string $xmlContent): array
    {
        // Remove BOM if present
        $xmlContent = ltrim($xmlContent, "\xEF\xBB\xBF");

        // Register namespace for XPath
        $xml = new SimpleXMLElement($xmlContent);
        $namespaces = $xml->getNamespaces(true);
        $ns = $namespaces[''] ?? null;

        // Use namespace-aware access or fallback
        if ($ns) {
            $xml->registerXPathNamespace('g', $ns);

            return $this->parseWithNamespace($xml);
        }

        return $this->parseWithoutNamespace($xml);
    }

    private function parseWithNamespace(SimpleXMLElement $xml): array
    {
        // Extract root attributes
        $attributes = $xml->attributes();
        $versandweg = (string) $attributes['Versandweg'];
        $schemaVersion = (string) $attributes['schemaVersion'];
        $erstelltAm = (string) $attributes['ErstelltAm'];

        $data = [
            'versandweg' => $versandweg,
            'schema_version' => $schemaVersion,
            'erstellt_am' => Carbon::parse($erstelltAm),
            'amtliches_kennzeichen' => $this->xpathText($xml, '//g:AmtlichesKennzeichen'),
            'beauftragungsnummer' => $this->xpathText($xml, '//g:Beauftragungsnummer'),
            'sap_auftragsnummer' => $this->xpathText($xml, '//g:SAPAuftragsnummer'),
            'vorgangsnummer' => $this->xpathText($xml, '//g:Vorgangsnummer'),
            'kundenreferenzen' => [],
            'partner' => [],
            'status' => null,
        ];

        // Parse Kundenreferenzliste
        $kundenrefs = $xml->xpath('//g:Kundenreferenzliste/g:Kundenreferenz');
        foreach ($kundenrefs as $ref) {
            $ref->registerXPathNamespace('g', $xml->getNamespaces(true)['']);
            $data['kundenreferenzen'][] = [
                'typ' => (string) $ref->xpath('g:Typ')[0] ?? '',
                'nummer' => (string) $ref->xpath('g:Nummer')[0] ?? '',
            ];
        }

        // Parse Partnerliste
        $partners = $xml->xpath('//g:Partnerliste/g:Partner');
        foreach ($partners as $partnerNode) {
            $partnerNode->registerXPathNamespace('g', $xml->getNamespaces(true)['']);
            $partner = [
                'name' => (string) ($partnerNode->xpath('g:Name')[0] ?? ''),
                'name2' => $this->optionalXpath($partnerNode, 'g:Name2'),
                'name4' => $this->optionalXpath($partnerNode, 'g:Name4'),
                'strasse' => $this->optionalXpath($partnerNode, 'g:Strasse'),
                'plz' => $this->optionalXpath($partnerNode, 'g:PLZ'),
                'ort' => $this->optionalXpath($partnerNode, 'g:Ort'),
                'land' => $this->optionalXpath($partnerNode, 'g:Land'),
                'nummer' => $this->optionalXpath($partnerNode, 'g:Nummer'),
                'telefonnummer' => $this->optionalXpath($partnerNode, 'g:Telefonnummer'),
                'faxnummer' => $this->optionalXpath($partnerNode, 'g:Faxnummer'),
                'email' => null,
                'rollen' => [],
            ];

            // Email
            $emailNodes = $partnerNode->xpath('g:Email');
            if (! empty($emailNodes)) {
                $emailNodes[0]->registerXPathNamespace('g', $xml->getNamespaces(true)['']);
                $partner['email'] = $this->optionalXpath($emailNodes[0], 'g:Bezeichnung');
            }

            // Multiple Rolle elements
            $rolleNodes = $partnerNode->xpath('g:Rolle');
            foreach ($rolleNodes as $rolle) {
                $partner['rollen'][] = trim((string) $rolle);
            }

            $data['partner'][] = $partner;
        }

        // Parse Status
        $statusNodes = $xml->xpath('//g:Status');
        if (! empty($statusNodes)) {
            $statusNodes[0]->registerXPathNamespace('g', $xml->getNamespaces(true)['']);
            $zusatzinfo = $this->optionalXpath($statusNodes[0], 'g:Zusatzinformation');
            $data['status'] = [
                'bezeichnung' => (string) ($statusNodes[0]->xpath('g:Bezeichnung')[0] ?? ''),
                'zusatzinformation' => $zusatzinfo ? Carbon::parse($zusatzinfo) : null,
            ];
        }

        return $data;
    }

    private function parseWithoutNamespace(SimpleXMLElement $xml): array
    {
        $attributes = $xml->attributes();
        $versandweg = (string) $attributes['Versandweg'];
        $schemaVersion = (string) $attributes['schemaVersion'];
        $erstelltAm = (string) $attributes['ErstelltAm'];

        $data = [
            'versandweg' => $versandweg,
            'schema_version' => $schemaVersion,
            'erstellt_am' => Carbon::parse($erstelltAm),
            'amtliches_kennzeichen' => (string) $xml->AmtlichesKennzeichen,
            'beauftragungsnummer' => (string) $xml->Beauftragungsnummer,
            'sap_auftragsnummer' => (string) $xml->SAPAuftragsnummer,
            'vorgangsnummer' => (string) $xml->Vorgangsnummer,
            'kundenreferenzen' => [],
            'partner' => [],
            'status' => null,
        ];

        // Parse Kundenreferenzliste
        if (isset($xml->Kundenreferenzliste->Kundenreferenz)) {
            foreach ($xml->Kundenreferenzliste->Kundenreferenz as $ref) {
                $data['kundenreferenzen'][] = [
                    'typ' => (string) $ref->Typ,
                    'nummer' => (string) $ref->Nummer,
                ];
            }
        }

        // Parse Partnerliste
        if (isset($xml->Partnerliste->Partner)) {
            foreach ($xml->Partnerliste->Partner as $partnerNode) {
                $partner = [
                    'name' => (string) $partnerNode->Name,
                    'name2' => $partnerNode->Name2 ? (string) $partnerNode->Name2 : null,
                    'name4' => $partnerNode->Name4 ? (string) $partnerNode->Name4 : null,
                    'strasse' => $partnerNode->Strasse ? (string) $partnerNode->Strasse : null,
                    'plz' => $partnerNode->PLZ ? (string) $partnerNode->PLZ : null,
                    'ort' => $partnerNode->Ort ? (string) $partnerNode->Ort : null,
                    'land' => $partnerNode->Land ? (string) $partnerNode->Land : null,
                    'nummer' => $partnerNode->Nummer ? (string) $partnerNode->Nummer : null,
                    'telefonnummer' => $partnerNode->Telefonnummer ? (string) $partnerNode->Telefonnummer : null,
                    'faxnummer' => $partnerNode->Faxnummer ? (string) $partnerNode->Faxnummer : null,
                    'email' => null,
                    'rollen' => [],
                ];

                if (isset($partnerNode->Email)) {
                    $partner['email'] = $partnerNode->Email->Bezeichnung
                        ? (string) $partnerNode->Email->Bezeichnung
                        : null;
                }

                // Multiple Rolle elements
                foreach ($partnerNode->Rolle as $rolle) {
                    $partner['rollen'][] = trim((string) $rolle);
                }

                $data['partner'][] = $partner;
            }
        }

        // Parse Status
        if (isset($xml->Status)) {
            $zusatzinfo = $xml->Status->Zusatzinformation
                ? (string) $xml->Status->Zusatzinformation
                : null;
            $data['status'] = [
                'bezeichnung' => (string) $xml->Status->Bezeichnung,
                'zusatzinformation' => $zusatzinfo ? Carbon::parse($zusatzinfo) : null,
            ];
        }

        return $data;
    }

    private function xpathText(SimpleXMLElement $xml, string $xpath): string
    {
        $result = $xml->xpath($xpath);

        return ! empty($result) ? trim((string) $result[0]) : '';
    }

    private function optionalXpath(SimpleXMLElement $node, string $path): ?string
    {
        $result = $node->xpath($path);
        if (empty($result)) {
            return null;
        }
        $value = trim((string) $result[0]);

        return $value !== '' ? $value : null;
    }
}
