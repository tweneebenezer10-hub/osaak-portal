<?php
declare(strict_types=1);

function school_learning_validate_assignment_input(
    mixed $title,
    mixed $instructions,
    mixed $learningMaterialUrl,
    mixed $dueAtInput,
    DateTimeZone $timezone
): array {
    if (!is_string($title)
        || !is_string($instructions)
        || !is_string($learningMaterialUrl)
        || !is_string($dueAtInput)) {
        throw new InvalidArgumentException('Enter the assignment details as text.');
    }

    $title = trim($title);
    $instructions = trim($instructions);
    $learningMaterialUrl = trim($learningMaterialUrl);

    if ($title === '' || strlen($title) > 180) {
        throw new InvalidArgumentException('Enter an assignment title of no more than 180 characters.');
    }
    if ($instructions === '' || strlen($instructions) > 12000) {
        throw new InvalidArgumentException('Enter assignment instructions of no more than 12,000 characters.');
    }

    $safeMaterialUrl = null;
    if ($learningMaterialUrl !== '') {
        $parts = parse_url($learningMaterialUrl);
        if (strlen($learningMaterialUrl) > 1000
            || filter_var($learningMaterialUrl, FILTER_VALIDATE_URL) === false
            || !is_array($parts)
            || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || empty($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])) {
            throw new InvalidArgumentException('Provide a valid HTTP or HTTPS learning-material link.');
        }
        $safeMaterialUrl = $learningMaterialUrl;
    }

    $dueAt = null;
    if ($dueAtInput !== '') {
        $parsedDueAt = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $dueAtInput, $timezone);
        $dateErrors = DateTimeImmutable::getLastErrors();
        if ($parsedDueAt === false
            || $parsedDueAt->format('Y-m-d\TH:i') !== $dueAtInput
            || ($dateErrors !== false
                && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))) {
            throw new InvalidArgumentException('Enter a valid assignment due date and time.');
        }
        $dueAt = $parsedDueAt->format('Y-m-d H:i:s');
    }

    return [
        'title' => $title,
        'instructions' => $instructions,
        'learning_material_url' => $safeMaterialUrl,
        'due_at' => $dueAt,
    ];
}

function school_learning_external_http_url(?string $url): ?string
{
    $url = trim((string) $url);
    if ($url === '') {
        return null;
    }
    $parts = parse_url($url);
    if (strlen($url) > 1000
        || filter_var($url, FILTER_VALIDATE_URL) === false
        || !is_array($parts)
        || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
        || empty($parts['host'])
        || isset($parts['user'])
        || isset($parts['pass'])) {
        return null;
    }
    return $url;
}
