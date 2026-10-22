<?php

$config = require __DIR__ . '/config.php';

function clickup($method, $endpoint, $payload = null)
{
    global $config;
    $ch = curl_init("https://api.clickup.com/api/v2/" . $endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => [
            "Authorization: " . $config['clickup_token'],
            "Content-Type: application/json"
        ]
    ]);
    if ($payload) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
    }
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'code' => $code,
        'data' => json_decode($res, true)
    ];
}

/* =========================
   استخراج فیلدها + گزینه‌های Dropdown
========================= */

$categories = [
    "Deals"    => "901710113555",
    "Clients"  => "901710113609",
    "Payments" => "901710113690"
];

$result = [];

foreach ($categories as $name => $listId) {
    $response = clickup('GET', "list/{$listId}/field");

    if ($response['code'] !== 200) {
        $result[$name] = ["error" => "HTTP " . $response['code']];
        continue;
    }

    $fields = $response['data']['fields'] ?? [];

    $formatted = [];
    foreach ($fields as $field) {
        $item = [
            "id"    => $field['id'] ?? null,
            "title" => $field['name'] ?? null,
            "type"  => $field['type'] ?? null,
        ];

        // استخراج گزینه‌های Dropdown / Labels
        if (in_array($field['type'], ['drop_down', 'labels'])) {
            $options = $field['type_config']['options'] ?? [];
            $item['options'] = [];

            foreach ($options as $opt) {
                $item['options'][] = [
                    "id"    => $opt['id'] ?? null,
                    "name"  => $opt['name'] ?? null,
                    "color" => $opt['color'] ?? null,
                ];
            }
        }

        $formatted[] = $item;
    }

    $result[$name] = [
        "list_id" => $listId,
        "fields"  => $formatted
    ];
}

// خروجی JSON
header('Content-Type: application/json; charset=utf-8');
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);