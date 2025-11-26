<?php
namespace Jules\Component\AgentEngine\Administrator\View\Agent;

defined('_JEXEC') or die;

use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Factory;

class HtmlView extends BaseHtmlView
{
    protected $form;

    public function display($tpl = null)
    {
        $this->form = $this->get('Form');
        $this->item = $this->get('Item');

        $this->addToolbar();

        return parent::display($tpl);
    }

    protected function addToolbar()
    {
        $isNew = ($this->item->id == 0);
        ToolbarHelper::title(Text::_($isNew ? 'COM_AGENTENGINE_AGENT_NEW' : 'COM_AGENTENGINE_AGENT_EDIT'));
        ToolbarHelper::apply('agent.apply');
        ToolbarHelper::save('agent.save');
        ToolbarHelper::cancel('agent.cancel');
    }
}
