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
  'mail' => [
    // Wenn leer -> PHP mail()
    // Optional: Später erweiterbar auf SMTP ohne externe Libraries (über fsockopen),
    // aber erstmal bewusst einfach gehalten.
    'from_email' => 'no-reply@example.org',
    'from_name'  => 'LEG Tool',
  ],
];
