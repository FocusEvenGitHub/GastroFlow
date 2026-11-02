#!/usr/bin/env bash
# Checks the PHP error settings of a built image (specs 073 and 074). The test suites run on
# setup-php or the development stack, so they never see these settings.
#
#   .github/scripts/image-php-check.sh <image>
#
# Every credential here is made up, and the container has no network.
set -euo pipefail

image="${1:?usage: image-php-check.sh <image>}"
failed=0

fail() {
  echo "FAIL: $1"
  failed=1
}

ini=$(docker run --rm "$image" php -i)
for setting in display_errors display_startup_errors log_errors zend.exception_ignore_args; do
  case "$setting" in
    display_errors | display_startup_errors) value=Off ;;
    *) value=On ;;
  esac
  if grep -qFx "$setting => $value => $value" <<< "$ini"; then
    echo "ok: $setting => $value"
  else
    fail "$setting is not $value"
  fi
done

password='FAKEPASSWORD123456789'
script='
require "vendor/autoload.php";
$capsule = new Illuminate\Database\Capsule\Manager();
$capsule->addConnection([
    "driver" => "mysql",
    "host" => "127.0.0.1",
    "database" => "fake_db",
    "username" => "fake_user",
    "password" => "'"$password"'",
]);
$capsule->getConnection()->select("select 1");
'

# This failure is the point of the check, so its exit code is not.
output=$(docker run --rm --network none "$image" php -r "$script" 2>&1 || true)

if grep -qF 'createPdoConnection' <<< "$output"; then
  echo "ok: the trace still names the failing function"
else
  fail "the connection failure left no trace: the check did not exercise it"
fi
if grep -qF "${password:0:15}" <<< "$output"; then
  fail "the database password appears in the trace"
else
  echo "ok: no password in the trace"
fi
if grep -qF "'fake_user'" <<< "$output"; then
  fail "the database user appears in the trace"
else
  echo "ok: no database user in the trace"
fi

exit "$failed"
