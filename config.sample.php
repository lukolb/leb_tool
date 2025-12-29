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
  'ai' => [
    // Optional: API-Schlüssel für KI-Vorschläge (z.B. OpenAI). Kann auch über
    // die Umgebungsvariable OPENAI_API_KEY gesetzt werden.
    'enabled' => true,
    'api_key' => '',
    // Optional: Für OpenAI-Teams kann hier eine Organisations-ID hinterlegt werden,
    // damit Billing-Abfragen nicht mit 403 abgelehnt werden.
    'organization' => '',
    'provider' => 'openai',
    'base_url' => 'https://api.openai.com',
    'model' => 'gpt-4o-mini',
    'timeout_seconds' => 20,
  ],
  'mail' => [
    // Wenn leer -> PHP mail()
    // Optional: Später erweiterbar auf SMTP ohne externe Libraries (über fsockopen),
    // aber erstmal bewusst einfach gehalten.
    'from_email' => 'no-reply@example.org',
    'from_name'  => 'LEG Tool',
  ],
];
