<?php

/*
 * Die Beschriftungen der Einstellungsseite.
 *
 * Feldschlüssel sind der Config-Pfad mit flachgelegten Punkten
 * (`thumbnails.width` → `thumbnails_width`).
 */

return [

    'permission_group' => 'Funnels',
    'permission_manage' => 'Funnel-Einstellungen verwalten',

    'groups' => [

        'pages' => [
            'title' => 'Funnel-Seiten',
            'description' => 'Was ein Besucher auf einer Funnel-Seite sieht. Der URL-Präfix steht weiterhin in der Datei config/statamic-funnels.php: er wird beim Registrieren der Routen gelesen, wirkte hier also erst beim nächsten Deploy, und diese Adressen stehen auf gedrucktem Material.',
        ],

        'thumbnails' => [
            'title' => 'Seitenbilder',
            'description' => 'Die Bilder auf den Karten im Funnel-Editor. Dateisystem und Chromium-Pfad stehen weiterhin in config/statamic-funnels.php, weil sie die Maschine beschreiben und nicht die Seite; ebenso die Cookies und die auszublendenden Selektoren, die eine Abbildung beziehungsweise eine Selektorenliste sind.',
        ],

        'integrations' => [
            'title' => 'Nachbar-Addons',
            'description' => 'Was ein abgeschlossener Funnel an andere Addons weitergibt. Der Schalter für Entitlements steht weiterhin nur in der Config: er wird an keiner Stelle dieses Addons gelesen und schaltet deshalb nichts.',
        ],

    ],

    'fields' => [

        'styles' => [
            'label' => 'Mitgelieferte Gestaltung laden',
            'description' => 'Aus heißt: die Funnel-Seiten binden weder das mitgelieferte Stylesheet noch das Skript ein. Die Klassennamen im Markup bleiben, eigenes CSS greift also weiter; ein Schritt mit Countdown zeigt ohne das Skript keine laufende Uhr mehr.',
        ],

        'coupons' => [
            'label' => 'Gutscheinfeld anzeigen',
            'description' => 'Aus heißt: die Angebotsseite zeigt kein Feld für einen Code, und ein trotzdem mitgeschickter Code wird beim Fortschreiten ignoriert. Für Seiten, die nie rabattieren.',
        ],

        'template_prefix' => [
            'label' => 'Ordner für eigene Templates',
            'description' => 'Ein Ordnername beschränkt die Templates, die ein Schritt selbst benennen darf, auf diesen Ordner. Leer heißt: überall unterhalb des views-Verzeichnisses. Sinnvoll, sobald andere Leute Funnels redigieren als Templates schreiben.',
        ],

        'password_reset_url' => [
            'label' => 'Seite für vergessene Passwörter',
            'description' => 'Dorthin wird ein Besucher geschickt, dessen Konto es schon gibt. Leer heißt: das Reset-Formular des Control Panels.',
        ],

        'thumbnails_enabled' => [
            'label' => 'Seitenbilder erzeugen',
            'description' => 'Aus heißt: nach dem Speichern wird kein Bild mehr erzeugt und die Karten im Editor behalten das, was sie schon haben. Bereits erzeugte Bilder werden nicht gelöscht.',
        ],

        'thumbnails_width' => [
            'label' => 'Breite in Pixeln',
            'description' => 'Die gespeicherte Breite neuer Bilder. Die Karte zeichnet in 16:10, ein anderes Verhältnis wird beschnitten. Vorhandene Bilder behalten ihre Größe, bis der Schritt wieder gespeichert wird.',
        ],

        'thumbnails_height' => [
            'label' => 'Höhe in Pixeln',
            'description' => 'Die gespeicherte Höhe neuer Bilder. Siehe Breite.',
        ],

        'integrations_leadhub' => [
            'label' => 'Adressen an LeadHub geben',
            'description' => 'An heißt: eine im Funnel erfasste Adresse wird als Kontakt an goldnead/statamic-leadhub übergeben. Ohne installiertes LeadHub passiert nichts.',
        ],

    ],

];
