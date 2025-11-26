<?php
namespace Jules\Component\AgentEngine\Administrator\Controller;

defined('_JEXEC') or die;

use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Factory;
use Joomla\Input\Json;

class PlaygroundController extends BaseController
{
    public function chat()
    {
        $app = Factory::getApplication();
        $input = $app->input;
        $id = $input->getInt('id');
        $message = $input->json->get('message');

        require_once JPATH_ROOT . '/vendor/autoload.php';

        $model = $this->getModel('Agent');
        $agentData = $model->getItem($id);

        $providerClass = 'NeuronAI\\Providers\\' . $agentData->provider . '\\' . $agentData->provider;
        $provider = new $providerClass($agentData->model);

        $agent = new \NeuronAI\Agent($provider);
        $agent->systemPrompt($agentData->system_prompt);

        try {
            $responseMessage = $agent->chat($message);
            $response = new \stdClass();
            $response->response = $responseMessage->getContent();
        } catch (\Exception $e) {
            $response = new \stdClass();
            $response->response = 'Error: ' . $e->getMessage();
        }

        echo new Json(array('success' => true, 'data' => $response));
        $app->close();
    }
}
