<?php
namespace Jules\Component\AgentEngine\Administrator\Table;

defined('_JEXEC') or die;

use Joomla\CMS\Table\Table;
use Joomla\Database\DatabaseDriver;

class AgentTable extends Table
{
    public function __construct(DatabaseDriver $db)
    {
        parent::__construct('#__agentengine_agents', 'id', $db);
    }
}
