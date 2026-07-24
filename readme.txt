=== Reptilien Manager ===
Contributors: feroxz
Tags: reptilien, bartagame, zucht, genetik, futterplan
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.2.0
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
* Die Vorlagen zeigen automatisch Verpaarungen und Nachzuchten des Tieres – mit Verlinkung zum Partner und zu den Nachzucht-Tieren sowie Gelege-Übersicht (Eier, geschlüpft, erwarteter Schlupf)
* Ansprechendes Layout: Banner-Karte mit Name und Morph, Foto neben Datentabelle, gestreifte Tabellen, Galerie-Raster und Abschnitts-Icons
* Direkter Bild-Upload in der Fotogalerie des Tieres (zusätzlich zur Mediathek-Auswahl)

**Genetik & Verpaarung**

* Genanlagen pro Tier: Hypo, Translucent, Zero, Witblits, Genetic Stripe (rezessiv), Leatherback/Silkback (unvollständig dominant), Dunner (dominant)
* Verpaarungen planen: Vater und Mutter auswählen, Verpaarungsdatum und Inkubationstemperatur festhalten
* Gelege-Verwaltung pro Verpaarung: mehrere Gelege mit Ablagedatum, Anzahl gelegter Eier, tatsächlich geschlüpfter Anzahl und automatisch berechnetem ungefähren Schlupfdatum (Ablage + 60 Tage)
* Nachzuchten werden beim Speichern automatisch als Tier-Entwürfe angelegt (pro Gelege entsprechend der geschlüpften Anzahl) – verknüpft mit der Verpaarung, inkl. Schlupfdatum und Art
* Abstammung am Tier: eigene Nachzuchten können ihrer Eltern-Verpaarung (und dem Gelege) zugeordnet werden
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
