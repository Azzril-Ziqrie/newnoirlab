<?php
require_once __DIR__ . '/lib/seo.php';

header('Content-Type: text/plain; charset=UTF-8');

$blockedPaths = ['/admin/', '/admin-login', '/payment', '/order-success', '/order-status', '/config/', '/lib/', '/kasera-webhook'];
$aiCrawlers   = ['GPTBot', 'ChatGPT-User', 'OAI-SearchBot', 'ClaudeBot', 'Claude-Web', 'PerplexityBot', 'Google-Extended', 'Applebot-Extended', 'CCBot'];

echo "User-agent: *\n";
echo "Allow: /\n";
foreach ($blockedPaths as $path) {
    echo "Disallow: $path\n";
}
echo "\n";

foreach ($aiCrawlers as $crawler) {
    echo "User-agent: $crawler\nAllow: /\n";
    foreach ($blockedPaths as $path) {
        echo "Disallow: $path\n";
    }
    echo "\n";
}

echo 'Sitemap: ' . seoAbsoluteUrl('sitemap.xml') . "\n";
