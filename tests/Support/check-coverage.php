<?php

declare(strict_types=1);

if ($argc !== 3) {
    fwrite(STDERR, "Usage: php check-coverage.php <clover.xml> <minimum-percent>\n");
    exit(2);
}

$coverageFile = $argv[1];
$minimumPercent = (float)$argv[2];

if (!is_file($coverageFile)) {
    fwrite(STDERR, sprintf("Coverage report not found: %s\n", $coverageFile));
    exit(2);
}

$document = new DOMDocument();
if (!$document->load($coverageFile)) {
    fwrite(STDERR, sprintf("Unable to parse coverage report: %s\n", $coverageFile));
    exit(2);
}

$xpath = new DOMXPath($document);
$metrics = $xpath->query('/coverage/project/metrics')->item(0);
if (!$metrics instanceof DOMElement) {
    fwrite(STDERR, "Coverage report does not contain project metrics\n");
    exit(2);
}

$statements = (int)$metrics->getAttribute('statements');
$coveredStatements = (int)$metrics->getAttribute('coveredstatements');
if ($statements === 0) {
    fwrite(STDERR, "Coverage report contains no executable statements\n");
    exit(2);
}

$percentage = ($coveredStatements / $statements) * 100;
printf(
    "Line coverage: %.2f%% (%d/%d), required: %.2f%%\n",
    $percentage,
    $coveredStatements,
    $statements,
    $minimumPercent
);

if ($percentage < $minimumPercent) {
    fwrite(STDERR, "Coverage is below the required threshold\n");
    exit(1);
}
