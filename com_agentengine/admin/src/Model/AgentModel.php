<?php
namespace Jules\Component\AgentEngine\Administrator\Model;

defined('_JEXEC') or die;

use Joomla\CMS\MVC\Model\AdminModel;
use Joomla\CMS\Factory;
use Joomla\CMS\Table\Table;

class AgentModel extends AdminModel
{
    public function getTable($type = 'Agent', $prefix = 'Jules\\Component\\AgentEngine\\Administrator\\Table', $config = [])
    {
        return Table::getInstance($type, $prefix, $config);
    }

    public function getForm($data = [], $loadData = true)
    {
        $form = $this->loadForm(
            'com_agentengine.agent',
            'agent',
            ['control' => 'jform', 'load_data' => $loadData]
        );

        if (empty($form)) {
            return false;
        }

        return $form;
    }

    protected function loadFormData()
    {
        $data = Factory::getApplication()->getUserState('com_agentengine.edit.agent.data', []);

        if (empty($data)) {
            $data = $this->getItem();
        }

        return $data;
    }
}
