<?php

declare(strict_types=1);

namespace Pixel\Module\Audit\EventSubscriber;

use Pixel\Module\Audit\Core\Diagnostics;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

final class EmployeeLoginSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [LoginSuccessEvent::class => ['onLoginSuccess', -100]];
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        try {
            $user = $event->getUser();
            if (!$user instanceof \PrestaShopBundle\Entity\Employee\Employee || !\Module::isEnabled('pixel_audit')) {
                return;
            }
            $module = \Module::getInstanceByName('pixel_audit');
            if ($module instanceof \Pixel_audit) {
                $module->recordEmployeeLogin($user->getId(), (string) $event->getRequest()->getClientIp());
            }
        } catch (\Throwable $error) {
            Diagnostics::report($error);
        }
    }
}
