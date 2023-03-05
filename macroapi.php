<?php
/*
Plugin Name: Macrocalculator API
Plugin URI: https://github.com/Solomon04/react-macro
Description: This is a custom plugin that allows us to email users that complete the macro calculator form.
Version: 1.0
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

    $response = add_subscriber_to_convert_kit($email, $fullName, $firstName);

    if ($response['status'] !== 200) {
        return new WP_Error(400, $response['message']);
    }

    try {
        send_sendgrid_email($email, $firstName, $tdee, $diets);
    } catch (Exception $exception) {
        return new WP_Error(400, $exception->getMessage());
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
    $mail->setFrom('info@exercisewithstyle.com');
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

function add_subscriber_to_convert_kit($email, $fullName, $firstName)
{
    $client = new Client();

    $response = $client->request('POST', 'https://api.convertkit.com/v3/forms/4918012/subscribe', [
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
