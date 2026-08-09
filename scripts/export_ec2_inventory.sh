#!/bin/bash
# export_ec2_inventory.sh — write the EC2 instance list for the admin Servers page.
#
# The Servers page joins this export to agent check-ins. Driving it from EC2
# rather than from agents is the point: a page built only from agents cannot show
# a server that is not reporting — an unenrolled or dead-agent host would simply
# be absent rather than flagged.
#
# Runs on the API host, which reaches EC2 through its instance profile. No
# credentials are stored here.
#
# Install (as root on the API host):
#   install -m 0755 export_ec2_inventory.sh /usr/local/sbin/
#   install -d -o www-data -g www-data /var/lib/winpatchagent
#   printf '%s\n' '*/30 * * * * root /usr/local/sbin/export_ec2_inventory.sh >/dev/null 2>&1' \
#     > /etc/cron.d/winpatchagent-ec2-inventory
#
# Requires ec2:DescribeInstances on the host's instance profile.
# Override the destination with PATCH_API_EC2_INVENTORY_PATH (must match the API).

set -uo pipefail

# Force the instance profile rather than any static credentials on the host.
# The API host carries an IAM user (Route53-Management, for certbot DNS-01) in a
# shared credentials file, which takes precedence over IMDS and does not have
# ec2:DescribeInstances. Isolating the credential chain here keeps this export on
# the narrowly-scoped, auto-rotating instance role instead of widening a
# long-lived user key.
export AWS_SHARED_CREDENTIALS_FILE=/dev/null
export AWS_CONFIG_FILE=/dev/null
unset AWS_ACCESS_KEY_ID AWS_SECRET_ACCESS_KEY AWS_SESSION_TOKEN AWS_PROFILE

OUT="${PATCH_API_EC2_INVENTORY_PATH:-/var/lib/winpatchagent/ec2-inventory.json}"
REGION="${AWS_REGION:-us-east-1}"
OWNER="${EC2_INVENTORY_OWNER:-www-data}"

command -v aws >/dev/null 2>&1 || { echo "export_ec2_inventory: aws CLI not found" >&2; exit 1; }

TMP=$(mktemp "${OUT}.XXXXXX") || { echo "export_ec2_inventory: cannot write next to $OUT" >&2; exit 1; }
trap 'rm -f "$TMP"' EXIT

# Running instances only. Stopped ones are noise on a patch-compliance view, and
# a stopped host that should be patched is a separate conversation.
INSTANCES=$(aws ec2 describe-instances \
  --region "$REGION" \
  --filters Name=instance-state-name,Values=running \
  --query 'Reservations[].Instances[].{instance_id:InstanceId,name:Tags[?Key==`Name`]|[0].Value,state:State.Name,private_ip:PrivateIpAddress,public_ip:PublicIpAddress}' \
  --output json 2>"${TMP}.err")

if [ $? -ne 0 ] || [ -z "$INSTANCES" ]; then
  echo "export_ec2_inventory: describe-instances failed:" >&2
  sed 's/^/  /' "${TMP}.err" >&2
  rm -f "${TMP}.err"
  # Leave any previous export in place. A stale file is visible on the page via
  # its timestamp; a truncated one would silently shrink the fleet.
  exit 1
fi
rm -f "${TMP}.err"

COUNT=$(printf '%s' "$INSTANCES" | python3 -c 'import json,sys; print(len(json.load(sys.stdin)))' 2>/dev/null || echo 0)
if [ "$COUNT" = "0" ]; then
  echo "export_ec2_inventory: zero running instances returned; keeping previous export" >&2
  exit 1
fi

printf '%s' "$INSTANCES" | python3 -c '
import json, sys, datetime
instances = json.load(sys.stdin)
json.dump({
    "generated_at": datetime.datetime.now(datetime.timezone.utc).isoformat(),
    "instances": instances,
}, sys.stdout, indent=2)
' > "$TMP" || { echo "export_ec2_inventory: failed to render JSON" >&2; exit 1; }

chmod 0644 "$TMP"
chown "$OWNER":"$OWNER" "$TMP" 2>/dev/null || true
mv -f "$TMP" "$OUT"          # atomic: readers never see a partial file
trap - EXIT

echo "export_ec2_inventory: wrote $COUNT instances to $OUT"
