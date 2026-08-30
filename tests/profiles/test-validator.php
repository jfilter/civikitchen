<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/packages/civicrm-profile-schema/validate.php';

function validator_expect(bool $condition, string $message): void {
  if (!$condition) {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
  }
}

$schema = [
  'type' => 'object',
  'required' => ['version', 'port', 'name'],
  'properties' => [
    'version' => ['type' => 'integer'],
    'port' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 65535],
    'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 2],
  ],
];
$validator = new CkProfileSchemaValidator($schema);
validator_expect($validator->validate(json_decode('{"version":1.0,"port":65535,"name":"äö"}')) === [], 'Draft 2020-12 integer and Unicode length semantics');
validator_expect($validator->validate(json_decode('{"version":1,"port":70000,"name":"ok"}')) !== [], 'maximum is enforced');
validator_expect($validator->validate(json_decode('{"version":1,"port":80,"name":"abc"}')) !== [], 'maxLength is enforced');

$profileSchema = json_decode(
  (string) file_get_contents(dirname(__DIR__, 2) . '/packages/civicrm-profile-schema/profile.schema.json'),
  TRUE,
  512,
  JSON_THROW_ON_ERROR,
);
$profileValidator = new CkProfileSchemaValidator($profileSchema);
$badIdentity = json_decode('{"description":"bad identity","dependencies":[],"apiUsers":[{"username":"bad:name","role":"civikitchen_safe","permissions":["access CiviCRM"]}]}');
validator_expect($profileValidator->validate($badIdentity) !== [], 'credential delimiters are rejected in usernames');
$tooLongIdentity = json_decode('{"description":"long identity","dependencies":[],"apiUsers":[{"username":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","role":"civikitchen_safe","permissions":["access CiviCRM"]}]}');
validator_expect($profileValidator->validate($tooLongIdentity) !== [], 'cross-CMS usernames are bounded to 60 characters');
$badRole = json_decode('{"description":"bad role","dependencies":[],"apiUsers":[{"username":"safe","role":"Api-Role","permissions":["access CiviCRM"]}]}');
validator_expect($profileValidator->validate($badRole) !== [], 'role identifiers use the portable lowercase machine-name intersection');
$missingGitVersion = json_decode('{"description":"missing pin","dependencies":[{"name":"org.example.demo","repo":"https://example.org/demo.git"}]}');
validator_expect($profileValidator->validate($missingGitVersion) !== [], 'every git repository requires an explicit version');
$unsupportedTrack = json_decode('{"description":"moving branch","dependencies":[{"name":"org.example.demo","repo":"https://example.org/demo.git","version":"main","track":"branch"}]}');
validator_expect($profileValidator->validate($unsupportedTrack) !== [], 'unsupported moving-branch tracking is rejected');
$jwtOnly = json_decode('{"description":"unusable credentials","dependencies":[],"authx":{"header_cred":["jwt"]},"apiUsers":[{"username":"safe","role":"civikitchen_safe","permissions":["access CiviCRM"]}]}');
validator_expect($profileValidator->validate($jwtOnly) !== [], 'JWT-only AuthX is rejected because no JWT credential is generated');

$enumValidator = new CkProfileSchemaValidator(['type' => 'integer', 'enum' => [1]]);
validator_expect($enumValidator->validate(json_decode('1.0')) === [], 'JSON Schema enum compares numeric values mathematically');

foreach (glob(dirname(__DIR__, 2) . '/docker/profiles/*/profile.json') ?: [] as $profileFile) {
  $profile = json_decode((string) file_get_contents($profileFile), TRUE, 512, JSON_THROW_ON_ERROR);
  foreach ($profile['dependencies'] as $dependency) {
    if (isset($dependency['repo'])) {
      validator_expect(
        preg_match('/^[0-9a-f]{40}$/', (string) ($dependency['version'] ?? '')) === 1,
        basename(dirname($profileFile)) . ':' . $dependency['name'] . ' uses an immutable git commit',
      );
    }
  }
}

$verein = json_decode(
  (string) file_get_contents(dirname(__DIR__, 2) . '/docker/profiles/verein/profile.json'),
  TRUE,
  512,
  JSON_THROW_ON_ERROR,
);
$contactLayout = array_values(array_filter(
  $verein['dependencies'],
  static fn(array $dependency): bool => $dependency['name'] === 'org.civicrm.contactlayout',
));
validator_expect(count($contactLayout) === 1, 'verein declares Contact Layout exactly once');
validator_expect(
  !isset($contactLayout[0]['repo'])
    && !isset($contactLayout[0]['version'])
    && ($contactLayout[0]['enable'] ?? false) === true
    && ($contactLayout[0]['skipUf'] ?? []) === ['Joomla']
    && trim((string) ($contactLayout[0]['skipUfReason'] ?? '')) !== '',
  'verein enables the core-packaged Contact Layout without replacing it and skips Joomla where it is absent',
);

echo "profile validator tests passed\n";
