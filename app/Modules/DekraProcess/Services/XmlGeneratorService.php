<?php

namespace App\Modules\DekraProcess\Services;

use XMLWriter;

class XmlGeneratorService
{
    /**
     * Generate DEKRA Auftrag XML from order data.
     *
     * @param  array  $auftragData  Joined order data (kunden_auftrag + objekt + orte + client)
     * @param  array  $anlagen  Attachment list
     * @param  array  $partner  Partner data
     * @return string Generated XML string
     */
    public function generate(array $auftragData, array $anlagen, array $partner): string
    {
        $xml = new XMLWriter;
        $xml->openMemory();
        $xml->startDocument('1.0', 'UTF-8');
        $xml->setIndent(true);
        $xml->setIndentString('  ');

        // <Auftrag> root element with attributes
        $xml->startElement('Auftrag');
        $xml->writeAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $xml->writeAttribute('xmlns:xsd', 'http://www.w3.org/2001/XMLSchema');
        $xml->writeAttribute('schemaVersion', '1.0');
        $xml->writeAttribute('Versandweg', 'Import');
        $xml->writeAttribute('xmlns', 'https://dekra.com/dxc/gadl');

        // <Beauftragungsnummer>
        $xml->writeElement('Beauftragungsnummer', $auftragData['beauftragungsnummer']);

        // <Dienstleistung>
        $xml->startElement('Dienstleistung');

        // <Anlagenliste>
        if (! empty($anlagen)) {
            $xml->startElement('Anlagenliste');
            foreach ($anlagen as $anlage) {
                $xml->startElement('Anlage');
                $xml->writeElement('Beschreibung', $anlage['beschreibung']);
                $xml->writeElement('Inhalt', $anlage['inhalt']);
                $xml->writeElement('MIMEType', $anlage['mime_type']);
                $xml->writeElement('Name', $anlage['feile_name']);
                $xml->writeElement('Typ', $anlage['feile_typ']);
                $xml->endElement(); // Anlage
            }
            $xml->endElement(); // Anlagenliste
        }

        // <Bemerkung>
        $bemerkung = $auftragData['auftrag_bemerkung'] ?? 'Bemerkungen zum Auftrag bzw. für die Disposition';
        $xml->writeElement('Bemerkung', $bemerkung);

        // <Dienstleistungsdaten>
        $xml->startElement('Dienstleistungsdaten');
        $xml->startElement('Besichtigungen');
        $xml->startElement('GewuenschteBesichtigung');

        // <Datum> (optional, use current date + 7 days if not provided)
        $datum = $auftragData['besichtigung_datum'] ?? now()->addDays(7)->format('Y-m-d');
        $xml->writeElement('Datum', $datum);

        // <Ort>
        $xml->startElement('Ort');
        $xml->writeElement('Name', $auftragData['orte_name']);
        $xml->writeElement('Name4', $auftragData['name4'] ?? 'Niederlassung');
        $xml->writeElement('Strasse', $auftragData['strasse']);
        $xml->writeElement('PLZ', $auftragData['plz']);
        $xml->writeElement('Ort', $auftragData['ort']);
        $xml->writeElement('Rolle', $auftragData['rolle']);
        $xml->endElement(); // Ort

        // <Uhrzeit> (optional)
        $uhrzeit = $auftragData['besichtigung_uhrzeit'] ?? '10:30:00';
        $xml->writeElement('Uhrzeit', $uhrzeit);

        $xml->endElement(); // GewuenschteBesichtigung
        $xml->endElement(); // Besichtigungen
        $xml->endElement(); // Dienstleistungsdaten

        // <Dienstleistungsobjekt Art="PKW">
        $xml->startElement('Dienstleistungsobjekt');
        $xml->writeAttribute('Art', $auftragData['objekt_art']);
        $xml->writeElement('AmtlichesKennzeichen', $auftragData['amtliches_kennzeichen']);
        $xml->writeElement('Erstzulassung', $auftragData['erstzulassung']);
        $xml->writeElement('Fahrzeugidentifizierungsnummer', $auftragData['fahrzeugidentifizierungsnummer']);
        $xml->writeElement('Hersteller', $auftragData['hersteller']);
        $xml->writeElement('Verkaufsbezeichnung', $auftragData['verkaufsbezeichnung']);
        $xml->endElement(); // Dienstleistungsobjekt

        // <Kundenreferenzliste>
        $xml->startElement('Kundenreferenzliste');
        $xml->startElement('Kundenreferenz');
        $xml->writeElement('Typ', 'Leasingnummer');
        $xml->writeElement('Nummer', $auftragData['leasing_nummer']);
        $xml->endElement(); // Kundenreferenz
        $xml->endElement(); // Kundenreferenzliste

        // <Materialnummer>
        $xml->writeElement('Materialnummer', '700118');

        // <Partnerliste> (inside Dienstleistung)
        $xml->startElement('Partnerliste');
        $xml->startElement('Partner');
        $xml->writeElement('Name', $partner['partner_name']);
        $xml->writeElement('Nummer', $partner['partner_nummer']);
        $xml->writeElement('Rolle', $partner['partner_rolle']);
        $xml->endElement(); // Partner
        $xml->endElement(); // Partnerliste

        $xml->endElement(); // Dienstleistung

        // <Partnerliste> (top-level, same partner)
        $xml->startElement('Partnerliste');
        $xml->startElement('Partner');
        $xml->writeElement('Name', $partner['partner_name']);
        $xml->writeElement('Nummer', $partner['partner_nummer']);
        $xml->writeElement('Rolle', $partner['partner_rolle']);
        $xml->endElement(); // Partner
        $xml->endElement(); // Partnerliste

        $xml->endElement(); // Auftrag
        $xml->endDocument();

        return $xml->outputMemory();
    }
}
