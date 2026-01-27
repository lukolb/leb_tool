<?php
// config.sample.php
define('DB_DATETIME_TZ', 'UTC');
return [
  'db' => [
    'host' => 'localhost',
    'port' => 3306,
    'name' => '',
    'user' => '',
    'pass' => '',
    'charset' => 'utf8mb4',
  ],
  'app' => [
    'session_name' => 'legtool_sess',
    'password_pepper' => '',
    // Schul-Zeitzone (IANA, z.B. "Europe/Berlin") für alle Zeitstempel im UI
    'timezone' => 'America/New_York',

    // Wird bei Installation automatisch gesetzt:
    // z.B. '/leb_pdf'
    'base_path' => '',

    // z.B. 'https://schultool.com/leb_pdf'
    'public_base_url' => '',

    // Optional: Default-Schuljahr (für Bulk-Import, wenn CSV nichts enthält)
    // Beispiel: '2025/26'
    'default_school_year' => '',

    // Branding (kann bei Installation gesetzt werden)
    'brand' => [
      'primary' => '#0b57d0',
      'secondary' => '#111111',
      'logo_path' => '', // z.B. 'uploads/branding/logo.png' (relativ zum Tool-Root)
      'org_name' => 'LEG Tool',
    ],

    // Uploads
    'uploads_dir' => 'uploads',
  ],
  'student' => [
    // Standard-Einstellungen für Vorlesen (TTS)
    'tts_voice_de' => '', // vits-web Voice-ID, z.B. "de_DE-thorsten-medium", leer = Auto
    'tts_voice_en' => '', // vits-web Voice-ID, z.B. "en_US-lessac-medium", leer = Auto
    'tts_voice' => '', // legacy fallback (Deutsch)
    'tts_rate' => 1.0, // 1.0 = normal, 0.5 = langsam, 1.5 = schnell
  ],
  'parent' => [
    // Eltern dürfen eine signierte, schreibgeschützte PDF herunterladen.
    'download_enabled' => false,
    // Elternzugänge werden ohne Admin-Bestätigung automatisch freigegeben.
    'auto_approve_requests' => false,
    // Grafische Lehrkraft-Unterschrift für den Parent-Export.
    'signature_enabled' => true,
    // Feedbackbogen nach dem Lernentwicklungsgespräch aktivieren.
    'meeting_feedback_enabled' => false,
    // Feedbackbogen ist verpflichtend, bevor der Bericht angezeigt wird.
    'meeting_feedback_required' => false,
    // Feedbackbogen anonym erfassen (Eltern sehen Hinweis im Portal).
    'meeting_feedback_anonymous' => false,
  ],
  'signature' => [
    // 32-Byte Master-Key (hex/base64 oder raw). Kann auch via SIGNATURE_MASTER_KEY gesetzt werden.
    'master_key' => '',
  ],
  'ai' => [
    // Optional: API-Schlüssel für KI-Vorschläge (z.B. OpenAI). Kann auch über
    // die Umgebungsvariable OPENAI_API_KEY gesetzt werden.
    'enabled' => true,
    // Separater Schalter für KI-Erklärungen in der Schüleransicht.
    'student_enabled' => true,
    'api_key' => '',
    // Provider & Modell können in den Einstellungen gewählt werden.
    'provider' => 'openai',
    'base_url' => 'https://api.openai.com',
    'model' => 'gpt-4o-mini',
    'timeout_seconds' => 60,
  ],
  'mail' => [
    // Wenn leer -> PHP mail()
    // Optional: Später erweiterbar auf SMTP ohne externe Libraries (über fsockopen),
    // aber erstmal bewusst einfach gehalten.
    'from_email' => 'no-reply@example.org',
    'from_name'  => 'LEG Tool',
  ],
];
