=== Reptilien Manager ===
Contributors: feroxz
Tags: reptilien, bartagame, zucht, genetik, futterplan
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Verwaltung von Reptilien – speziell Bartagamen: Tiere mit Fotos und Daten, Verpaarungen mit Genetik-Vorschau und Futterplanung.

== Description ==

Reptilien Manager hilft Haltern und Züchtern von Reptilien – mit Fokus auf Bartagamen (Pogona vitticeps) – bei der Verwaltung ihres Bestands:

**Tierverwaltung**

* Eigene Tiere als eigener Inhaltstyp „Reptilien“ mit Profilfoto und Fotogalerie
* Stammdaten: Geschlecht, Schlupfdatum (mit automatischer Altersberechnung), Herkunft/Züchter, Erwerbsdatum, Kennzeichnung, Gesamtlänge
* Gewichtsverlauf mit beliebig vielen Wiegungen
* Freitext für Haltung, Gesundheit und Besonderheiten
* Arten-Taxonomie (Standard: Bartagame)
* Beitrags-Vorlagen: Steckbrief (Tabelle), Ausführliches Porträt, Zuchttier-Präsentation und Kurzprofil – der Beitragstext wird per Klick automatisch aus den eingetragenen Daten erzeugt, inklusive Profilfoto und Galerie (Gutenberg-Blöcke, Classic-Editor-kompatibel)

**Genetik & Verpaarung**

* Genanlagen pro Tier: Hypo, Translucent, Zero, Witblits, Genetic Stripe (rezessiv), Leatherback/Silkback (unvollständig dominant), Dunner (dominant)
* Verpaarungen planen: Vater und Mutter auswählen, Verpaarungsdatum, Eiablage, Gelegegröße, Inkubationstemperatur, erwarteter Schlupftermin
* Genetik-Vorschau der Jungtiere nach Mendelscher Vererbung (Punnett): kombinierte Ergebnisse mit Wahrscheinlichkeiten sowie Aufschlüsselung pro Gen, inkl. Kombi-Morph Wero (Zero × Witblits)
* Eigenständiger Genetik-Rechner zum Durchspielen beliebiger Paarungen

**Futterplanung**

* Altersgerechter Bartagamen-Futterplan (Jungtier, Heranwachsend, Subadult, Adult) mit Empfehlungen für Insekten, Grünfutter und Supplemente (Calcium, Calcium+D3, Vitamine)
* Fütterungsprotokoll: Tier, Datum, Futterart, Menge, Supplemente und Notizen
* Übersichtsseite mit Empfehlung und letzter Fütterung pro Tier

**Frontend**

* Shortcode `[reptilien]` – Kartenübersicht aller veröffentlichten Tiere (optional `sex="male"` oder `sex="female"`)
* Shortcode `[reptil id="123"]` – Detailprofil eines Tieres mit Galerie

== Installation ==

1. Plugin-Ordner in `wp-content/plugins/` hochladen oder als ZIP installieren.
2. Plugin im WordPress-Backend aktivieren.
3. Im Menü „Reptilien“ Tiere anlegen, Genanlagen pflegen und Verpaarungen planen.

== Frequently Asked Questions ==

= Für welche Arten ist das Plugin geeignet? =

Der Fokus liegt auf Bartagamen (Genetik-Rechner und Futterplan). Über die Arten-Taxonomie lassen sich aber beliebige Reptilien verwalten.

= Wie funktioniert die Genetik-Vorschau? =

Pro Gen wird die Mendelsche Vererbung (Punnett-Quadrat) berechnet und über alle Gene kombiniert. „het“ bezeichnet Träger eines rezessiven Gens ohne sichtbare Ausprägung.

== Changelog ==

= 1.1.0 =
* Neu: Beitrags-Vorlagen beim Eintragen eines Tieres. Vier Layouts (Steckbrief, Ausführliches Porträt, Zuchttier-Präsentation, Kurzprofil) füllen den Textbereich automatisch mit den aktuell eingetragenen Tierdaten, dem Profilfoto und der Fotogalerie.

= 1.0.0 =
* Erste Version: Tierverwaltung mit Fotos, Genetik-Rechner, Verpaarungsplanung und Futterplanung für Bartagamen.
