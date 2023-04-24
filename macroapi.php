<?php
/*
Plugin Name: Macrocalculator API
Plugin URI: https://github.com/Solomon04/react-macro
Description: This is a custom plugin that allows us to email users that complete the macro calculator form.
Version: 1.3
Author: Solomon <solomon@icodestuff.io>
*/

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

use Rakit\Validation\Validator;
use SendGrid\Mail\Mail;
use GuzzleHttp\Client;
use Asana\Client as Asana;


// Your plugin code goes here
add_action('rest_api_init', function () {
    register_rest_route('macro/v1', '/submit', array(
        'methods' => 'POST',
        'callback' => 'handle',
    ));
});

function handle($request)
{
    $params = $request->get_params();
    $validation = (new Validator())->make($params, [
        'full_name' => 'required',
        'email' => 'required|email',
        'tdee' => 'required|numeric',
        'diets' => 'required',
        'training_experience' => 'required',
        'macro_experience' => 'required',
        'unit' => 'required',
        'weight' => 'required|numeric',
        'activity_level' => 'required',
        'goal' => 'required',
        'call_to_action' => 'required',
        'wants_consulting' => 'required'
    ]);

    $validation->validate();
    if ($validation->fails()) {
        // handling errors
        $errors = $validation->errors();
        return new WP_Error(400, 'Validation failed', [
            'data' => $errors->firstOfAll()
        ]);
    }

    $email = $params['email'];
    $fullName = $params['full_name'];
    $nameArray = explode(" ", $fullName);
    $firstName = $nameArray[0] ?? '';
    $tdee = $params['tdee'];
    $diets = $params['diets'];

    $response = add_subscriber_to_macro_entry_tag($email, $fullName, $firstName);

    if ($response['status'] !== 200) {
        return new WP_Error(400, $response['message']);
    }

    try {
        send_sendgrid_email($email, $firstName, $tdee, $diets);
    } catch (Exception $exception) {
        return new WP_Error(400, $exception->getMessage());
    }

    if ($params['wants_consulting'] == 'Yes') {
        try {
            add_submission_to_asana($params);
        }catch (\Asana\Errors\AsanaError $exception) {
            return new WP_Error($exception->getCode(), $exception->getMessage());
        }

        $response = add_subscriber_to_macro_service_tag($email, $fullName, $firstName);

        if ($response['status'] !== 200) {
            return new WP_Error(400, $response['message']);
        }
    }

    return new WP_REST_Response(
        array(
            'status' => 'success',
            'response' => 'Sent email to convertkit & sendgrid',
        )
    );
}

/**
 * @param $email
 * @param $fullName
 * @param $tdee
 * @param $diets
 * @return \SendGrid\Response
 * @throws \SendGrid\Mail\TypeException
 */
function send_sendgrid_email($email, $firstName, $tdee, $diets)
{
    $sendgrid = new SendGrid(getenv('SENDGRID_API_KEY'));
    $mail = new Mail();
    $mail->setFrom('info@exercisewithstyle.com', 'Exercise With Style');
    $mail->setTemplateId(getenv('SENDGRID_TEMPLATE_ID'));
    $mail->addTo($email);
    $mail->addDynamicTemplateDatas([
        'email' => $email,
        'first_name' => $firstName,
        'tdee' => $tdee,
        'diets' => $diets
    ]);

    return $sendgrid->send($mail);
}

function add_subscriber_to_macro_entry_tag($email, $fullName, $firstName)
{
    $client = new Client();

    $response = $client->request('POST', 'https://api.convertkit.com/v3/tags/3722110/subscribe', [
        'headers' => [
            'Content-Type' => 'application/json; charset=utf-8',
        ],
        'json' => [
            'api_key' => getenv('CONVERT_KIT_KEY'),
            'email' => $email,
            'first_name' => $firstName,
            'name' => $fullName
        ],
    ]);

    return [
        'status' => $response->getStatusCode(),
        'message' => $response->getBody()->getContents()
    ];
}

function add_subscriber_to_macro_service_tag($email, $fullName, $firstName)
{
    $client = new Client();

    $response = $client->request('POST', 'https://api.convertkit.com/v3/tags/3730007/subscribe', [
        'headers' => [
            'Content-Type' => 'application/json; charset=utf-8',
        ],
        'json' => [
            'api_key' => getenv('CONVERT_KIT_KEY'),
            'email' => $email,
            'first_name' => $firstName,
            'name' => $fullName
        ],
    ]);

    return [
        'status' => $response->getStatusCode(),
        'message' => $response->getBody()->getContents()
    ];
}

function add_submission_to_asana($params)
{
    $asana = Asana::accessToken(getenv('ASANA_PERSONAL_ACCESS_TOKEN'));

    $template = file_get_contents(__DIR__ . '/asana-template.html');
    $weight = sprintf("%d%s", $params['weight'], $params['unit'] === 'Metric' ? 'kg' : 'lbs');
    $height = sprintf("%d%s", $params['height'], $params['unit'] === 'Metric' ? 'cm' : 'in');
    $body = str_replace([
        '{full_name}',
        '{email}',
        '{training_experience}',
        '{macro_experience}',
        '{unit}',
        '{weight}',
        '{height}',
        '{activity_level}',
        '{goal}',
        '{call_to_action}',
        '{wants_consulting}',
    ], [
        $params['full_name'],
        $params['email'],
        $params['training_experience'],
        $params['macro_experience'],
        $params['unit'],
        $weight,
        $height,
        $params['activity_level'],
        $params['goal'],
        $params['call_to_action'],
        $params['wants_consulting'],
    ], $template);

    $data = [
        'name' => "{$params['full_name']} macro submission - " . date("Y-m-d"),
        'projects' => ['1204051454931105'],
        'workspace' => '1201738035299370',
        'html_notes' => $body
    ];

    return $asana->tasks->createTask($data, ['opt_pretty' => true, 'opt_fields' => ['custom_fields', 'html_notes', 'name', 'workspace', 'projects']]);
}
