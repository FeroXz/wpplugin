=== Reptilien Manager ===
Contributors: feroxz
Tags: reptilien, bartagame, zucht, genetik, futterplan
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.9.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Verwaltung von Reptilien – Bartagame und Grüner Leguan: Tiere mit Fotos und Daten, artspezifische Genetik-Vorschau und artgerechte Futterplanung.

== Description ==

Reptilien Manager hilft Haltern und Züchtern von Reptilien bei der Verwaltung ihres Bestands. Genetik und Futterplan sind artspezifisch – aktuell für Bartagame (Pogona vitticeps, Allesfresser) und Grünen Leguan (Iguana iguana, strikter Pflanzenfresser):

**Tierverwaltung**

* Eigene Tiere als eigener Inhaltstyp „Reptilien“ mit Profilfoto und Fotogalerie
* Stammdaten: Geschlecht, Schlupfdatum (mit automatischer Altersberechnung), Herkunft/Züchter, Erwerbsdatum, Kennzeichnung, Gesamtlänge
* Erweiterte Morphologie & Kondition: KRL, Schwanzlänge, Umfang, Körperkonditions-Score (BCS 1–5), Temperament (1–5), Farbintensität und Häutungs-Intervall
* Gewichtsverlauf als interaktive Chart.js-Graphik (Alter × Gewicht) mit erwarteter Referenz-Wachstumskurve pro Art, Anomalie-Erkennung (unter-/übergewichtig) und CSV-Export
* Freitext für Haltung, Gesundheit und Besonderheiten
* Arten-Taxonomie mit vorbereiteten Profilen: Bartagame (Pogona vitticeps) und Grüner Leguan (Iguana iguana) – Genetik-Set und Futterplan richten sich automatisch nach der zugeordneten Art
* Beitrags-Vorlagen: Steckbrief (Tabelle), Ausführliches Porträt, Zuchttier-Präsentation und Kurzprofil – der Beitragstext wird per Klick automatisch aus den eingetragenen Daten erzeugt, inklusive Profilfoto und Galerie (Gutenberg-Blöcke, Classic-Editor-kompatibel)
* Die Vorlagen zeigen automatisch Verpaarungen und Nachzuchten des Tieres – mit Verlinkung zum Partner und zu den Nachzucht-Tieren sowie Gelege-Übersicht (Eier, geschlüpft, erwarteter Schlupf)
* Ansprechendes Layout: Banner-Karte mit Name und Morph, Foto neben Datentabelle, gestreifte Tabellen, Galerie-Raster und Abschnitts-Icons
* Direkter Bild-Upload in der Fotogalerie des Tieres (zusätzlich zur Mediathek-Auswahl)

**Genetik & Verpaarung**

* Artspezifische Genanlagen pro Tier:
  * Bartagame: Hypo, Translucent, Zero, Witblits, Genetic Stripe (rezessiv), Leatherback/Silkback (unvollständig dominant), Dunner (dominant); Kombi-Morph Wero (Zero × Witblits)
  * Grüner Leguan: Albino (amelanistisch), Axanthic/Blau, Hypomelanistisch (alle rezessiv); Kombi-Morph Snow (Albino × Axanthic)
* Die Genetik-Vorschau nutzt automatisch das Gen-Set der Art; bei Elterntieren unterschiedlicher Arten wird gewarnt
* Verpaarungen planen: Vater und Mutter auswählen, Verpaarungsdatum und Inkubationstemperatur festhalten
* Gelege-Verwaltung pro Verpaarung: mehrere Gelege mit Ablagedatum, Anzahl gelegter Eier, tatsächlich geschlüpfter Anzahl und automatisch berechnetem ungefähren Schlupfdatum (Ablage + 60 Tage)
* Gelege-Verwaltung 2.0: pro Gelege Inkubationstemperatur, unbefruchtete und abgestorbene Eier (mit Absterbe-Tag); temperaturabhängige Schlupf-Vorhersage (z. B. 28 °C ≈ Tag 58–65, 31 °C ≈ Tag 50–54) mit grafischer Timeline; Schlupfquote in Prozent inkl. Vergleich zum Bestands-Durchschnitt
* Nachzuchten werden beim Speichern automatisch als Tier-Entwürfe angelegt (pro Gelege entsprechend der geschlüpften Anzahl) – verknüpft mit der Verpaarung, inkl. Schlupfdatum und Art
* Abstammung am Tier: eigene Nachzuchten können ihrer Eltern-Verpaarung (und dem Gelege) zugeordnet werden
* Genetik-Vorschau der Jungtiere nach Mendelscher Vererbung (Punnett): kombinierte Ergebnisse mit Wahrscheinlichkeiten sowie Aufschlüsselung pro Gen, inkl. Kombi-Morph Wero (Zero × Witblits)
* Eigenständiger Genetik-Rechner zum Durchspielen beliebiger Paarungen
* Inzucht-Koeffizient (COI nach Wright) für jede geplante Verpaarung – berechnet aus der hinterlegten Abstammung, mit Warnstufen (Geschwister/Halbgeschwister/entfernt)
* Verpaarungs-Empfehlungen: alle Kombinationen nach genetischer Vielfalt (niedriger COI), Artgleichheit und Zuchtreife sortiert; Zuchtstatistik pro Tier (Verpaarungen, Nachkommen, Ø Schlupfquote, eigener COI)
* Genetik-Export: Punnett-Ergebnis als JSON (Austausch mit anderen Züchtern) oder als druckbare Ansicht zum Speichern als PDF über den Browser
* Erwartet vs. Tatsächlich: Vergleich der erwarteten Morph-Verteilung mit den real eingetragenen Nachzuchten je Verpaarung (Lernfeedback, markiert unerwartete Morphe)

**Futterplanung**

* Artgerechter, altersgerechter Futterplan – Bartagame (Allesfresser: Insekten + Grünfutter) und Grüner Leguan (strikter Pflanzenfresser: Blattgrün, kein tierisches Eiweiß, MBD-/Gicht-Hinweise) – mit Supplement-Empfehlungen (Calcium, Calcium+D3, Vitamine)
* Schnell-Eintrag direkt auf der Futterplan-Seite: mehrere Tiere (oder „Alle Tiere“) und mehrere Futterarten gleichzeitig in einem Eintrag, plus Menge, Supplemente und Notizen
* Fütterungs-Auswertung: vergleicht die protokollierten Fütterungen der letzten 14 Tage pro Tier mit dem art- und altersgerechten Optimum (Insekten-, Grünfutter- und Calcium-Frequenz pro Woche) und zeigt farbige Status-Chips (optimal / zu wenig / zu viel) – beim Leguan wird jede Insektenfütterung als „zu viel“ markiert
* Nährstoff-Bilanz pro Tier: Calcium-, Vitamin-D3- und Vitamin-Frequenz gegen das art-/altersgerechte Ziel, inkl. Toxizitäts-Warnung bei Überdosierung (z. B. Vitamine/D3 zu häufig)
* Kosten-Tracking: Preis je Futterart hinterlegen; monatliche Gesamtkosten, Prognose sowie Aufschlüsselung pro Futterart und pro Tier
* Fütterungsprotokoll mit automatisch erzeugten Titeln („Fütterung 24.07.2026 – Alle Tiere“)
* Übersichtsseite mit Empfehlung, Auswertung, Nährstoff-Bilanz und letzter Fütterung pro Tier

**Frontend**

* Shortcode `[reptilien]` – filterbare Kartenübersicht aller Tiere mit Filterleiste (Geschlecht, Art, Morph, Alter min/max, Sortierung nach Name/Alter/Gewicht); Attribute `sex`, `species`, `morph`, `sort`, `filter="no"` als Vorbelegung
* Shortcode `[reptil id="123"]` – Detailprofil eines Tieres im Steckbrief-Layout mit Galerie
* Shortcode `[reptilien-dashboard]` – Bestands-Dashboard mit Kennzahlen (Gesamtzahl, Geschlechter-Split, Ø Gewicht, Ø Schlupfquote) und Chart.js-Diagrammen (Geschlechter-, Alters- und Morph-Verteilung)
* Shortcode `[reptilien-stammbaum id="123" generationen="3"]` – Ahnentafel eines Tieres mit verlinkten Vorfahren
* Shortcode `[reptilien-genetik]` – Genetik-Rechner im Frontend: zwei Tiere wählen, mögliche Jungtiere samt Wahrscheinlichkeiten und Inzucht-Koeffizient berechnen
* Design passend zum Theme „Wissenswerk“: übernimmt dessen CSS-Variablen (Farben, Dark-Mode) automatisch, funktioniert aber mit jedem Theme dank identischer Fallback-Werte

**Import / Export & Backup**

* JSON-Backup des kompletten Bestands (Tiere, Verpaarungen, Gelege, Fütterungen, Futterpreise) – für Sicherungen oder den Transfer zu einem anderen Züchter
* JSON-Wiederherstellung/Transfer: legt alles neu an und schreibt die Verweise (Eltern-Verpaarung, Vater/Mutter, Fütterungs-Tiere) automatisch auf die neuen IDs um
* CSV-Import für Altdaten (Spalten: Name, Geschlecht, Schlupfdatum, Art, Herkunft, Länge, Gewicht, Kennzeichnung) mit flexibler Datums- und Geschlechts-Erkennung
* PDF-Zuchtbuch: druckbare Gesamtübersicht aller Tiere (über den Browser als PDF speicherbar)

== Installation ==

1. Plugin-Ordner in `wp-content/plugins/` hochladen oder als ZIP installieren.
2. Plugin im WordPress-Backend aktivieren.
3. Im Menü „Reptilien“ Tiere anlegen, Genanlagen pflegen und Verpaarungen planen.

== Frequently Asked Questions ==

= Für welche Arten ist das Plugin geeignet? =

Vollständige artspezifische Profile (Genetik-Rechner und Futterplan) gibt es für Bartagame (Pogona vitticeps) und Grünen Leguan (Iguana iguana). Die Art wird pro Tier über die Arten-Taxonomie zugeordnet; Genetik-Set und Fütterungs-Optimum passen sich automatisch an. Weitere Reptilien lassen sich als zusätzliche Arten verwalten (dann mit dem Standard-Set der Bartagame).

= Wie funktioniert die Genetik-Vorschau? =

Pro Gen wird die Mendelsche Vererbung (Punnett-Quadrat) berechnet und über alle Gene kombiniert. „het“ bezeichnet Träger eines rezessiven Gens ohne sichtbare Ausprägung.

== Changelog ==

= 1.9.0 =
* Neu: Seite „Import / Export“ im Reptilien-Menü.
* Neu: JSON-Backup des gesamten Bestands (Export) und Wiederherstellung/Transfer mit automatischem Umschreiben aller ID-Verweise (Eltern-Verpaarung, Vater/Mutter, Fütterungs-Tiere).
* Neu: CSV-Import für Altdaten mit flexibler Spalten-, Datums- und Geschlechts-Erkennung.
* Neu: PDF-Zuchtbuch als druckbare Gesamtübersicht (über den Browser als PDF).
* Sicherheit: Nonce- und Capability-Prüfungen (JSON-Import nur für Administratoren); nur Plugin-eigene Meta-Schlüssel werden importiert.

= 1.8.0 =
* Neu (Genetik): Export eines Punnett-Ergebnisses als JSON und als druckbare PDF-Ansicht (über den Browser) – Buttons im Genetik-Rechner.
* Neu (Genetik): „Erwartet vs. Tatsächlich“ in der Verpaarung – vergleicht die erwartete Morph-Verteilung mit den eingetragenen Nachzuchten und markiert unerwartete Morphe.
* Neu (Frontend): Shortcode `[reptilien-stammbaum]` – Ahnentafel mit verlinkten Vorfahren (Generationen einstellbar).
* Neu (Frontend): Shortcode `[reptilien-genetik]` – Genetik-Rechner im Frontend inkl. Inzucht-Koeffizient.

= 1.7.0 =
* Neu (Genetik/Verpaarung): Inzucht-Koeffizient (COI nach Wright) je geplanter Verpaarung, berechnet aus der Abstammung, mit Warnstufen. Angezeigt in der Verpaarungs-Genetik-Box.
* Neu (Genetik/Verpaarung): Seite „Verpaarungs-Empfehlungen“ – ranked nach genetischer Vielfalt, Artgleichheit und Zuchtreife; plus Zuchtstatistik pro Tier (Verpaarungen, Nachkommen, Ø Schlupfquote, eigener COI).
* Neu (Frontend): Shortcode `[reptilien-dashboard]` mit Kennzahlen-Kacheln und Chart.js-Diagrammen (Geschlechter-, Alters-, Morph-Verteilung).
* Neu (Frontend): `[reptilien]` hat jetzt eine Filterleiste (Geschlecht, Art, Morph, Alter min/max) und Sortierung (Name/Alter/Gewicht); per `filter="no"` abschaltbar.
* Chart.js wird im Frontend nur bei Bedarf (Dashboard) geladen; Diagramme sind dark-mode-aware.

= 1.6.0 =
* Neu (Tierverwaltung): Gewichtsverlauf als Chart.js-Graphik mit erwarteter Wachstumskurve pro Art, Anomalie-Erkennung (unter-/übergewichtig, farbige Punkte + Tooltip) und CSV-Export.
* Neu (Tierverwaltung): erweiterte Stammdaten – KRL, Schwanzlänge, Umfang, Körperkonditions-Score (1–5), Temperament (1–5), Farbintensität, Häutungs-Intervall.
* Neu (Genetik/Verpaarung): Gelege-Verwaltung 2.0 mit Inkubationstemperatur, unbefruchteten/gestorbenen Eiern, temperaturabhängiger Schlupf-Vorhersage, grafischer Timeline und Schlupfquote inkl. Vergleich zum Durchschnitt.
* Neu (Futterplanung): Nährstoff-Bilanz (Calcium/D3/Vitamine) mit Toxizitäts-Warnung sowie Kosten-Tracking (Preise je Futterart, Monatskosten, Aufschlüsselung pro Futterart und Tier).
* Chart.js wird automatisch eingebunden (lokal bundelbar oder via CDN, per Filter `rm_chartjs_src` überschreibbar).
* Alle bestehenden Daten bleiben gültig; neue Felder sind optional.

= 1.5.0 =
* Neu: Grüner Leguan (Iguana iguana) als vollständige Art. Genetik und Futterplan sind jetzt artspezifisch.
* Neu: Leguan-Genetik mit Albino (amelanistisch), Axanthic (Blau) und Hypomelanistisch (rezessiv) sowie Kombi-Morph Snow (Albino × Axanthic).
* Neu: Leguan-Futterplan als strikter Pflanzenfresser (Blattgrün-Basis, kein tierisches Eiweiß, MBD-/Gicht-Hinweise) inkl. eigener Altersgruppen und Ziel-Frequenzen; jede Insektenfütterung wird in der Auswertung als „zu viel“ markiert.
* Neu: Zentrale Arten-Registry ordnet jedem Tier über die Taxonomie automatisch das passende Gen-Set und Futter-Optimum zu; die Genetik-Metabox zeigt die Morphe der zugeordneten Art.
* Verbessert: Genetik-Rechner warnt, wenn Elterntiere unterschiedlichen Arten angehören.

= 1.4.0 =
* Neu: Schnell-Eintrag für Fütterungen direkt auf der Futterplan-Seite – mehrere Tiere (inkl. „Alle Tiere“-Schalter) und mehrere Futterarten in einem Eintrag.
* Neu: Fütterungs-Auswertung pro Tier – Ist-Frequenz der letzten 14 Tage (Insekten, Grünfutter, Calcium) im Vergleich zum altersgerechten Optimum mit Status-Chips (optimal/zu wenig/zu viel).
* Verbessert: Fütterungs-Eintrag im Editor nutzt jetzt Checkbox-Raster für Tiere, Futterarten und Supplemente statt Einzel-Dropdowns; bestehende Einträge bleiben kompatibel.
* Verbessert: Automatische Titel für Fütterungen und Verpaarungen (Titelfeld kann leer bleiben); neue Tiere erhalten automatisch die Standard-Art Bartagame, wenn keine gewählt wurde.

= 1.3.0 =
* Design an das Theme „Wissenswerk“ angeglichen (Indigo/Cyan-Verlauf, Karten mit Hover-Effekt, Pill-Chips, weiche Radien und Schatten).
* Frontend: Tierübersicht als Karten-Raster mit Foto-Zoom, Geschlechts-Badge und Morph-Chip; Tierprofil im Steckbrief-Layout (Foto-Spalte mit Faktenliste, Inhalt rechts); Dark-Mode wird über die Theme-Variablen automatisch unterstützt.
* Beitrags-Vorlagen: Banner mit Marken-Verlauf und weißer Schrift, Karten mit abgerundeten Ecken in Theme-Farben.
* Admin: Tabellen, Galerie, Gelege- und Nachzucht-Listen im Theme-Look (Chips, Akzentbalken, Radien).

= 1.2.0 =
* Neu: Gelege-Verwaltung an Verpaarungen – mehrere Gelege mit Ablagedatum, Eizahl, geschlüpfter Anzahl und automatisch berechnetem Schlupftermin.
* Neu: Nachzuchten werden beim Speichern einer Verpaarung automatisch als Tier-Entwürfe angelegt und mit der Verpaarung verknüpft.
* Neu: Abstammungs-Feld am Tier (Eltern-Verpaarung + Gelege-Nummer) für eigene Nachzuchten.
* Neu: Direkter Bild-Upload in der Fotogalerie des Tieres.
* Neu: Beitrags-Vorlagen zeigen Verpaarungen, Gelege und Nachzuchten mit automatischer Verlinkung zu Partner und Jungtieren.
* Verbessert: Vorlagen visuell überarbeitet (Banner-Karte, Spalten-Layout, gestreifte Tabellen, Galerie-Raster, Abschnitts-Icons).

= 1.1.0 =
* Neu: Beitrags-Vorlagen beim Eintragen eines Tieres. Vier Layouts (Steckbrief, Ausführliches Porträt, Zuchttier-Präsentation, Kurzprofil) füllen den Textbereich automatisch mit den aktuell eingetragenen Tierdaten, dem Profilfoto und der Fotogalerie.

= 1.0.0 =
* Erste Version: Tierverwaltung mit Fotos, Genetik-Rechner, Verpaarungsplanung und Futterplanung für Bartagamen.
