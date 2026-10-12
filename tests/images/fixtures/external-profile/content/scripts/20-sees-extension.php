<?php
// Profile content runs after the stack's mounted extensions are enabled.
$status = CRM_Extension_System::singleton()->getManager()->getStatus('ckbootfixture');
if ($status !== CRM_Extension_Manager::STATUS_INSTALLED) {
  throw new \RuntimeException("content fixture script ran before the mounted extension was enabled (status {$status})");
}
echo "  content fixture script saw the mounted extension\n";
