<?php
namespace Collecting;

use Collecting\Permissions\Assertion\HasInputTextPermissionAssertion;
use Collecting\Permissions\Assertion\HasUserNamePermissionAssertion;
use Omeka\Module\AbstractModule;
use Zend\EventManager\Event;
use Zend\EventManager\SharedEventManagerInterface;
use Zend\Form\Fieldset;
use Zend\Mvc\MvcEvent;
use Zend\ServiceManager\ServiceLocatorInterface;

class Module extends AbstractModule
{
    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }

    public function onBootstrap(MvcEvent $event)
    {
        parent::onBootstrap($event);

        $services =  $this->getServiceLocator();

        $acl = $services->get('Omeka\Acl');
        $acl->allow(null, 'Collecting\Controller\Site\Index');
        $acl->allow(null, [
            'Collecting\Controller\SiteAdmin\Form',
            'Collecting\Controller\SiteAdmin\Item',
        ]);
        $acl->allow(null, [
            'Collecting\Api\Adapter\CollectingFormAdapter',
            'Collecting\Api\Adapter\CollectingItemAdapter'
        ], ['search', 'read']);
        $acl->allow(null, [
            'Collecting\Entity\CollectingForm',
            'Collecting\Entity\CollectingItem',
        ], ['read']);
        $acl->allow(
            null,
            'Collecting\Entity\CollectingInput',
            ['view-collecting-input-text'],
            new HasInputTextPermissionAssertion
        );
        $acl->allow(
            null,
            'Collecting\Entity\CollectingItem',
            ['view-collecting-user-name'],
            new HasUserNamePermissionAssertion
        );
    }

    public function install(ServiceLocatorInterface $services)
    {
        $conn = $services->get('Omeka\Connection');
        // Reduce installation time by toggling off foreign key checks.
        $conn->exec('SET FOREIGN_KEY_CHECKS = 0');
        $conn->exec('
CREATE TABLE collecting_item (id INT AUTO_INCREMENT NOT NULL, item_id INT NOT NULL, form_id INT NOT NULL, collecting_user_id INT NOT NULL, user_name VARCHAR(255) DEFAULT NULL, user_email VARCHAR(255) DEFAULT NULL, anon TINYINT(1) DEFAULT NULL, reviewed TINYINT(1) NOT NULL, created DATETIME NOT NULL, modified DATETIME DEFAULT NULL, UNIQUE INDEX UNIQ_D414538C126F525E (item_id), INDEX IDX_D414538C5FF69B7D (form_id), INDEX IDX_D414538CB0237C21 (collecting_user_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB;
CREATE TABLE collecting_prompt (id INT AUTO_INCREMENT NOT NULL, form_id INT NOT NULL, property_id INT DEFAULT NULL, position INT NOT NULL, type VARCHAR(255) NOT NULL, text LONGTEXT DEFAULT NULL, input_type VARCHAR(255) DEFAULT NULL, select_options LONGTEXT DEFAULT NULL, media_type VARCHAR(255) DEFAULT NULL, required TINYINT(1) NOT NULL, INDEX IDX_98FE9BA65FF69B7D (form_id), INDEX IDX_98FE9BA6549213EC (property_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB;
CREATE TABLE collecting_form (id INT AUTO_INCREMENT NOT NULL, item_set_id INT DEFAULT NULL, site_id INT NOT NULL, owner_id INT DEFAULT NULL, label VARCHAR(255) NOT NULL, anon_type VARCHAR(255) NOT NULL, success_text LONGTEXT NOT NULL, INDEX IDX_99878BDD960278D7 (item_set_id), INDEX IDX_99878BDDF6BD1646 (site_id), INDEX IDX_99878BDD7E3C61F9 (owner_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB;
CREATE TABLE collecting_input (id INT AUTO_INCREMENT NOT NULL, prompt_id INT NOT NULL, collecting_item_id INT NOT NULL, text LONGTEXT NOT NULL, INDEX IDX_C6E2CFC9B5C4AA38 (prompt_id), INDEX IDX_C6E2CFC9522FDEA (collecting_item_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB;
CREATE TABLE collecting_user (id INT AUTO_INCREMENT NOT NULL, user_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_469CA0DBA76ED395 (user_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB;
ALTER TABLE collecting_item ADD CONSTRAINT FK_D414538C126F525E FOREIGN KEY (item_id) REFERENCES item (id) ON DELETE CASCADE;
ALTER TABLE collecting_item ADD CONSTRAINT FK_D414538C5FF69B7D FOREIGN KEY (form_id) REFERENCES collecting_form (id) ON DELETE CASCADE;
ALTER TABLE collecting_item ADD CONSTRAINT FK_D414538CB0237C21 FOREIGN KEY (collecting_user_id) REFERENCES collecting_user (id) ON DELETE CASCADE;
ALTER TABLE collecting_prompt ADD CONSTRAINT FK_98FE9BA65FF69B7D FOREIGN KEY (form_id) REFERENCES collecting_form (id) ON DELETE CASCADE;
ALTER TABLE collecting_prompt ADD CONSTRAINT FK_98FE9BA6549213EC FOREIGN KEY (property_id) REFERENCES property (id) ON DELETE CASCADE;
ALTER TABLE collecting_form ADD CONSTRAINT FK_99878BDD960278D7 FOREIGN KEY (item_set_id) REFERENCES item_set (id) ON DELETE SET NULL;
ALTER TABLE collecting_form ADD CONSTRAINT FK_99878BDDF6BD1646 FOREIGN KEY (site_id) REFERENCES site (id) ON DELETE CASCADE;
ALTER TABLE collecting_form ADD CONSTRAINT FK_99878BDD7E3C61F9 FOREIGN KEY (owner_id) REFERENCES user (id) ON DELETE SET NULL;
ALTER TABLE collecting_input ADD CONSTRAINT FK_C6E2CFC9B5C4AA38 FOREIGN KEY (prompt_id) REFERENCES collecting_prompt (id) ON DELETE CASCADE;
ALTER TABLE collecting_input ADD CONSTRAINT FK_C6E2CFC9522FDEA FOREIGN KEY (collecting_item_id) REFERENCES collecting_item (id) ON DELETE CASCADE;
ALTER TABLE collecting_user ADD CONSTRAINT FK_469CA0DBA76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE SET NULL;
');
        $conn->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    public function uninstall(ServiceLocatorInterface $services)
    {
        $services->get('Omeka\Connection')->exec('
SET FOREIGN_KEY_CHECKS=0;
DROP TABLE IF EXISTS collecting_item;
DROP TABLE IF EXISTS collecting_prompt;
DROP TABLE IF EXISTS collecting_form;
DROP TABLE IF EXISTS collecting_input;
DROP TABLE IF EXISTS collecting_user;
SET FOREIGN_KEY_CHECKS=1;
DELETE FROM site_page_block WHERE layout = "collecting";
DELETE FROM site_setting WHERE id = "collecting_recaptcha_secret_key";
DELETE FROM site_setting WHERE id = "collecting_recaptcha_site_key";
DELETE FROM site_setting WHERE id = "collecting_tos";
');
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager)
    {
        $sharedEventManager->attach(
            '*',
            'site_settings.form',
            [$this, 'addSiteSettings']
        );

        $sharedEventManager->attach(
            '*',
            'sql_filter.resource_visibility',
            function (Event $event) {
                // Users can view collecting items only if they have permission
                // to view the attached item.
                $relatedEntities = $event->getParam('relatedEntities');
                $relatedEntities['Collecting\Entity\CollectingItem'] = 'item_id';
                $event->setParam('relatedEntities', $relatedEntities);
            }
        );

        // Add collecting data to the item show page.
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Item',
            'view.show.after',
            function (Event $event) {
                $view = $event->getTarget();
                $cItem = $view->api()
                    ->searchOne('collecting_items', ['item_id' => $view->item->id()])
                    ->getContent();
                if (!$cItem) {
                    // Don't render the partial if there's no collecting item.
                    return;
                }
                echo $view->partial('common/collecting-item-section', ['cItem' => $cItem]);
            }
        );

        $sharedEventManager->attach(
            'Omeka\Controller\Site\Item',
            'view.show.after',
            function (Event $event) {
                $view = $event->getTarget();
                $cItem = $view->api()
                    ->searchOne('collecting_items', ['item_id' => $view->item->id()])
                    ->getContent();
                if (!$cItem) {
                    // Don't render the link if there's no collecting item.
                    return;
                }
                echo '<p>' . $cItem->displayCitation() . '</p>';
                echo $view->hyperlink(
                    $view->translate('Click here to view the collected data.'),
                    $view->url('site/collecting-item', [
                        'site-slug' => $view->site->slug(),
                        'item-id' => $cItem->id(),
                    ])
                );
            }
        );

        // Add the collecting tab to the item show section navigation.
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Item',
            'view.show.section_nav',
            function (Event $event) {
                $view = $event->getTarget();
                $cItem = $view->api()
                    ->searchOne('collecting_items', ['item_id' => $view->item->id()])
                    ->getContent();
                if (!$cItem) {
                    // Don't render the tab if there's no collecting item.
                    return;
                }
                $sectionNav = $event->getParam('section_nav');
                $sectionNav['collecting-section'] = 'Collecting';
                $event->setParam('section_nav', $sectionNav);
            }
        );

        // Add the Collecting term definition to the JSON-LD context.
        $sharedEventManager->attach(
            '*',
            'api.context',
            function (Event $event) {
                $context = $event->getParam('context');
                $context['o-module-collecting'] = 'http://omeka.org/s/vocabs/module/collecting#';
                $event->setParam('context', $context);
            }
        );
    }

    /**
     * Add elements to the site settings form.
     *
     * @param Event $event
     */
    public function addSiteSettings(Event $event)
    {
        $services = $this->getServiceLocator();
        $siteSettings = $services->get('Omeka\SiteSettings');
        $form = $event->getParam('form');

        $fieldset = new Fieldset('collecting');
        $fieldset->setLabel('Collecting');

        // Add the reCAPTCHA site and secret keys to the form.
        $fieldset->add([
            'type' => 'text',
            'name' => 'collecting_recaptcha_site_key',
            'options' => [
                'label' => 'Collecting reCAPTCHA site key',
            ],
            'attributes' => [
                'value' => $siteSettings->get('collecting_recaptcha_site_key'),
            ],
        ]);
        $fieldset->add([
            'type' => 'text',
            'name' => 'collecting_recaptcha_secret_key',
            'options' => [
                'label' => 'Collecting reCAPTCHA secret key',
            ],
            'attributes' => [
                'value' => $siteSettings->get('collecting_recaptcha_secret_key'),
            ],
        ]);

        // Add the terms of service to the form.
        $fieldset->add([
            'type' => 'textarea',
            'name' => 'collecting_tos',
            'options' => [
                'label' => 'Collecting terms of service',
            ],
            'attributes' => [
                'value' => $siteSettings->get('collecting_tos'),
            ],
        ]);

        $form->add($fieldset);
    }
}
