<?php
// config.sample.php
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
