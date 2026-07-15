<?php
$secret = '215a2c8b4be207a691ae5f190547d7d2d493a1f098d3c0438d3d8532fd5b0fd4';

/*if (!isset($_GET['secret']) || $_GET['secret'] !== $secret) {
    http_response_code(403);
    exit('Forbidden');
}*/

// Read the raw request body
$payload = file_get_contents('php://input');

// Get GitHub's signature header
$githubSignature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';

if (empty($githubSignature)) {
    http_response_code(403);
    exit('Missing signature');
}

// Calculate what the signature should be
$expectedSignature = 'sha256=' . hash_hmac('sha256', $payload, $secret);

// Compare securely
if (!hash_equals($expectedSignature, $githubSignature)) {
    http_response_code(403);
    exit('Invalid signature');
}


$repo = '/home/creator4/repositories/floorplan';
$target = '/home/creator4/public_html/floorplan.maklarlabbet.se';

$output = [];

// Update the Git repository
exec("cd $repo && git pull origin main 2>&1", $output, $code);

if ($code !== 0) {
    echo implode("\n", $output);
    exit;
}

// Copy files to public_html
$output = [];
//exec("rsync -av --delete --exclude='.git' --exclude='.github' --exclude='.htaccess' --exclude='.user.ini' --exclude='php.ini' --exclude='.well-known' --exclude='git-deploy.php' $repo/ $target/ 2>&1", $output, $code);
exec("rsync -av --exclude='.git' --exclude='.github' --exclude='.htaccess' --exclude='.user.ini' --exclude='php.ini' --exclude='.well-known' --exclude='git-deploy.php' $repo/ $target/ 2>&1", $output, $code);

echo implode("\n", $output);

//http_response_code(200);
//exit('success');
?>