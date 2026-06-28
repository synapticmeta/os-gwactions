<?php

namespace OPNsense\GwActions;

/**
 * UI controller for the Gateway Actions page.
 */
class IndexController extends \OPNsense\Base\IndexController
{
    public function indexAction()
    {
        $this->view->generalForm = $this->getForm('general');
        $this->view->dialogRule = $this->getForm('dialogRule');
        $this->view->pick('OPNsense/GwActions/index');
    }
}
