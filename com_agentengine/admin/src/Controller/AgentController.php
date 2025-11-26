<?php
namespace Jules\Component\AgentEngine\Administrator\Controller;

defined('_JEXEC') or die;

use Joomla\CMS\MVC\Controller\AdminController;
use Joomla\CMS\Factory;

class AgentController extends AdminController
{
    public function getModel($name = 'Agent', $prefix = 'Jules\\Component\\AgentEngine\\Administrator\\Model', $config = ['ignore_request' => true])
    {
        return parent::getModel($name, $prefix, $config);
    }
}
