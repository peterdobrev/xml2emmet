# AWS demo-day playbook — xml2emmet on EB + RDS (Learner Lab)

A fast, copy-paste-friendly checklist for standing the demo back up after a teardown. Aimed at AWS Academy Learner Lab; assumes you've already run through `docs/aws-deploy.md` once successfully and just need the recipe.

**Total time from cold:** ~7 minutes. ~5 of those are RDS provisioning, hands-off.

**Region:** `us-east-1` (forced by Learner Lab).

---

## Prerequisites — the day before, not on demo morning

Run these the day before so demo morning has zero surprises. They each take seconds; they catch the things that age.

```bash
# 1. AWS CLIs are installed and current.
aws --version    # expect aws-cli/2.x
eb --version     # expect EB CLI 3.27+

# 2. Composer deps are vendored for the bundle.
cd /Users/I765724/Documents/xml2emmet
composer install --no-dev --optimize-autoloader
ls vendor/autoload.php   # must exist

# 3. The AWS branch with EB scaffolding is checked out.
git checkout feat/aws-elastic-beanstalk-rds
ls .ebextensions/01-php.config \
   .platform/hooks/postdeploy/01-migrate.sh \
   .platform/nginx/conf.d/elasticbeanstalk/01-router.conf \
   .ebignore

# 4. The platform string is still valid. If this returns nothing, the
#    AL2023 PHP 8.2 platform was retired and you need to pick a new one.
aws elasticbeanstalk list-available-solution-stacks --region us-east-1 --output json 2>/dev/null \
  | python3 -c "import json,sys; [print(s) for s in json.load(sys.stdin)['SolutionStacks'] if 'PHP 8.2' in s and '2023' in s]"
```

If the platform string changed, update both:

- `eb create` line below — search for `--platform` (we don't pass one because `eb init` wrote the default to `.elasticbeanstalk/config.yml`; rerun `eb init xml2emmet --platform "<NEW STRING>" --region us-east-1` to update it).
- `docs/aws-deploy.md` §3.1.

---

## Demo morning — start to finish

### 0. Get into the lab (Canvas — clicks only)

1. <https://awsacademy.instructure.com/> → your course → **Modules** → **Learner Lab**.
2. Click **Start Lab**. Wait for the AWS dot to turn **green** (~30–60 s).
3. Click **AWS Details** → **AWS CLI: Show**. Copy the `[default]` block (4 lines).
4. Paste into `~/.aws/credentials` (overwriting whatever's there from a prior session):

   ```bash
   # Either: paste manually with your editor of choice, or:
   pbpaste > ~/.aws/credentials   # macOS — assumes the [default] block is on your clipboard
   chmod 600 ~/.aws/credentials
   ```
5. Quick sanity check:

   ```bash
   aws sts get-caller-identity
   # Should print an ARN ending in :assumed-role/voclabs/user...
   ```

   If you get `ExpiredToken`, the credentials you copied have already aged out — End Lab → Start Lab → re-copy.

### 1. Create RDS (5–8 min, hands-off after kicking off)

```bash
cd /Users/I765724/Documents/xml2emmet

# Generate and stash a fresh password.
mkdir -p ~/.xml2emmet-aws
DB_PASSWORD=$(openssl rand -base64 24 | tr -d '+/=' | cut -c1-20)
echo "$DB_PASSWORD" > ~/.xml2emmet-aws/db_password
chmod 600 ~/.xml2emmet-aws/db_password
echo "DB_PASSWORD=$DB_PASSWORD"   # write this down or just trust the file

# Provision MySQL.
aws rds create-db-instance \
  --region us-east-1 \
  --db-instance-identifier xml2emmet-db \
  --db-instance-class db.t3.micro \
  --engine mysql --engine-version 8.0.46 \
  --allocated-storage 20 --storage-type gp2 \
  --master-username admin --master-user-password "$DB_PASSWORD" \
  --db-name xml2emmet \
  --backup-retention-period 0 --no-multi-az \
  --publicly-accessible --no-deletion-protection

# Wait for RDS to be available, then capture endpoint and SG.
aws rds wait db-instance-available --region us-east-1 --db-instance-identifier xml2emmet-db
DB_HOST=$(aws rds describe-db-instances --region us-east-1 \
  --db-instance-identifier xml2emmet-db \
  --query 'DBInstances[0].Endpoint.Address' --output text)
RDS_SG=$(aws rds describe-db-instances --region us-east-1 \
  --db-instance-identifier xml2emmet-db \
  --query 'DBInstances[0].VpcSecurityGroups[0].VpcSecurityGroupId' --output text)
echo "$DB_HOST" > ~/.xml2emmet-aws/db_host
echo "$RDS_SG"  > ~/.xml2emmet-aws/rds_sg
chmod 600 ~/.xml2emmet-aws/*
echo "DB_HOST=$DB_HOST  RDS_SG=$RDS_SG"
```

While RDS provisions (~5 min), continue to **Step 2** in another terminal — `eb init` is local-only, doesn't need RDS yet.

### 2. Initialize EB (local-only, ~5 s)

```bash
cd /Users/I765724/Documents/xml2emmet
eb init xml2emmet \
  --platform "PHP 8.2 running on 64bit Amazon Linux 2023" \
  --region us-east-1
# Decline SSH setup if prompted.
```

This rewrites `.elasticbeanstalk/config.yml`. No AWS resources created.

### 3. Open RDS to your laptop & migrate (~30 s after RDS is up)

Once Step 1's `wait` returns:

```bash
DB_HOST=$(cat ~/.xml2emmet-aws/db_host)
DB_PASS=$(cat ~/.xml2emmet-aws/db_password)
RDS_SG=$(cat ~/.xml2emmet-aws/rds_sg)
MY_IP=$(curl -s https://checkip.amazonaws.com)

# Allow MySQL inbound from your laptop.
aws ec2 authorize-security-group-ingress \
  --region us-east-1 --group-id "$RDS_SG" \
  --protocol tcp --port 3306 --cidr "$MY_IP/32"

# Apply the schema.
XML2EMMET_DB_HOST="$DB_HOST" XML2EMMET_DB_PORT=3306 \
XML2EMMET_DB_NAME=xml2emmet XML2EMMET_DB_USER=admin XML2EMMET_DB_PASS="$DB_PASS" \
  php bin/migrate.php
# Expected: "apply 001_init.sql … ok"
```

### 4. Create the EB environment (~5–8 min, hands-off)

```bash
DB_HOST=$(cat ~/.xml2emmet-aws/db_host)
DB_PASS=$(cat ~/.xml2emmet-aws/db_password)

eb create xml2emmet-prod \
  --single \
  --instance-type t3.micro \
  --service-role LabRole \
  --instance_profile LabInstanceProfile \
  --envvars "XML2EMMET_DB_HOST=${DB_HOST},XML2EMMET_DB_PORT=3306,XML2EMMET_DB_NAME=xml2emmet,XML2EMMET_DB_USER=admin,XML2EMMET_DB_PASS=${DB_PASS},XML2EMMET_SECURE_COOKIE=0,XML2EMMET_DEBUG=0" \
  --timeout 20
```

**This will fail at the postdeploy migration step** with `Connection timed out` because RDS hasn't yet allowed the EB instance's security group. **That's expected.** The instance, nginx, and php-fpm are all up; only the schema-migration hook errored. Step 5 fixes it.

If `eb create` errors out and exits without finishing, run `eb status` — if `Health: Red` and `Status: Ready`, the env exists and you can proceed. If the env doesn't exist, the failure is something else; jump to **Troubleshooting**.

### 5. Open EB → RDS, redeploy (~90 s)

```bash
RDS_SG=$(cat ~/.xml2emmet-aws/rds_sg)

EB_INSTANCE=$(aws ec2 describe-instances --region us-east-1 \
  --filters "Name=tag:elasticbeanstalk:environment-name,Values=xml2emmet-prod" \
            "Name=instance-state-name,Values=running" \
  --query 'Reservations[0].Instances[0].InstanceId' --output text)
EB_SG=$(aws ec2 describe-instances --region us-east-1 --instance-ids "$EB_INSTANCE" \
  --query 'Reservations[0].Instances[0].SecurityGroups[0].GroupId' --output text)

aws ec2 authorize-security-group-ingress --region us-east-1 \
  --group-id "$RDS_SG" --protocol tcp --port 3306 --source-group "$EB_SG"

eb deploy
# Expect: "Environment update completed successfully."
eb status     # Health should now go Green
```

### 6. Smoke-test (~10 s)

```bash
URL=$(eb status | awk -F': ' '/CNAME/ {print "http://"$2}')
echo "$URL"

JAR=$(mktemp)
curl -s -c "$JAR" -X POST "$URL/api/auth/register" \
  -H 'Content-Type: application/json' \
  --data '{"username":"demo","password":"hunter2hunter2"}' | python3 -m json.tool
curl -s -b "$JAR" -X POST "$URL/api/transform" \
  -H 'Content-Type: application/json' \
  --data '{"direction":"xml2emmet","input":"<div class=\"hero\"><h1>Live</h1></div>","settings":{"mode":"xml"},"save":true}' \
  | python3 -m json.tool
rm -f "$JAR"
```

If you see `{"user":{"id":1,...}}` and a transform `output` field, you're green-light for demo. Open `$URL/app.html` in a browser as your final visual check.

### 7. Demo

The URL from Step 6 is your demo URL. Talk track suggestion:

1. Open `app.html` — register, transform, show the result.
2. `eb status` — show Health: Green, the CNAME, the platform.
3. `eb logs --all | tail -40` — show the per-request log lines (`[reqid] 200 5ms POST /api/transform`).
4. AWS Console → RDS → xml2emmet-db → CloudWatch graphs (Performance Insights / connections) — "I get this for free."
5. AWS Console → Elastic Beanstalk → xml2emmet-prod → Configuration — "instance type, env vars, scaling are click-edits."

### 8. Tear down (~5 min, do this BEFORE clicking End Lab)

```bash
# Kick off RDS delete first; it takes longer.
aws rds delete-db-instance --region us-east-1 \
  --db-instance-identifier xml2emmet-db --skip-final-snapshot

# EB terminate (foreground, ~3-4 min — CLI may time out at 10 min, the
# operation continues server-side regardless).
eb terminate xml2emmet-prod --force

# Wait for RDS to fully delete.
aws rds wait db-instance-deleted --region us-east-1 --db-instance-identifier xml2emmet-db

# Revoke orphan ingress rules from the default VPC SG. The SG itself can't
# be deleted (it's the default), but leaving rules pointing at deleted SGs
# is messy.
RDS_SG=$(cat ~/.xml2emmet-aws/rds_sg)
MY_IP=$(curl -s https://checkip.amazonaws.com)
aws ec2 revoke-security-group-ingress --region us-east-1 --group-id "$RDS_SG" \
  --protocol tcp --port 3306 --cidr "$MY_IP/32" 2>/dev/null
# The EB-SG-referenced rule auto-disappears when the EB SG itself is deleted
# during eb terminate, so no explicit revoke needed for that one.

# Confirm everything is gone.
aws rds describe-db-instances --region us-east-1 \
  --query 'DBInstances[].DBInstanceIdentifier' --output text   # empty
aws elasticbeanstalk describe-environments --region us-east-1 \
  --application-name xml2emmet \
  --query 'Environments[?Status!=`Terminated`].EnvironmentName' --output text   # empty
```

Now click **End Lab** in Canvas.

---

## Troubleshooting (the things that will actually go wrong)

### `aws sts get-caller-identity` returns `ExpiredToken` or similar

Your Learner Lab session expired (4-hour timer) or you copied stale credentials. End Lab → Start Lab → AWS Details → CLI Show → re-paste into `~/.aws/credentials`.

### `eb create` fails with `AccessDenied` on IAM

Forgot `--service-role LabRole --instance_profile LabInstanceProfile`. Learner Lab won't let you create new IAM roles. Add those flags and retry.

### `eb create` fails with platform-not-found

The platform string is stale. Run:

```bash
aws elasticbeanstalk list-available-solution-stacks --region us-east-1 --output json \
  | python3 -c "import json,sys; [print(s) for s in json.load(sys.stdin)['SolutionStacks'] if 'PHP' in s]"
```

Pick a current PHP-on-AL2023 entry. Re-run `eb init xml2emmet --platform "<NEW>" --region us-east-1`, then `eb create ...`.

### First `eb create` ends in `Connection timed out` from `bin/migrate.php`

Expected. EB SG isn't in RDS SG yet. Run **Step 5** of this playbook, then `eb deploy`.

### `eb deploy` fails with `[emerg] "location" directive is not allowed here`

The nginx snippet is in the wrong directory. It must be at `.platform/nginx/conf.d/elasticbeanstalk/01-router.conf`, not `.platform/nginx/conf.d/01-router.conf`. The `elasticbeanstalk/` subdirectory is what makes it land inside the `server {}` block where `location` is legal.

### Static files (`/app.html`) work, but every `/api/*` returns nginx HTML 404

The nginx router snippet wasn't shipped or isn't being applied. Confirm:

```bash
ls .platform/nginx/conf.d/elasticbeanstalk/01-router.conf   # must exist
git status                                                   # not gitignored
```

If both are fine, `eb deploy` and check `eb logs` for nginx restart messages.

### `eb deploy` succeeds but the env stays Red, app returns 500s

`eb logs --all | grep -i error` and look for PDO errors. If `Connection timed out`, EB↔RDS SG rule is missing or wrong. If `Access denied for user 'admin'`, the password env var doesn't match what RDS expects (this happens if you regenerate `DB_PASSWORD` between Step 1 and Step 4 — re-run Step 4 with the right password, or `eb setenv XML2EMMET_DB_PASS=...`).

### Login works, but every subsequent request returns 401

`XML2EMMET_SECURE_COOKIE=1` while serving over HTTP. The cookie is set with `Secure` and the browser refuses to send it back. Set it to `0`:

```bash
eb setenv XML2EMMET_SECURE_COOKIE=0
```

Don't set it to `1` until you've put CloudFront/HTTPS in front (out of scope for the demo).

### `eb terminate` times out at 10 minutes

The CLI quits, but the server-side operation continues. Don't panic. Verify with:

```bash
aws elasticbeanstalk describe-environments --region us-east-1 \
  --application-name xml2emmet \
  --query 'Environments[].{Name:EnvironmentName,Status:Status}'
```

If `Status` is `Terminating` or `Terminated`, you're fine. The environment will fully disappear within a few minutes; nothing more to do.

### RDS delete hangs

Is `--skip-final-snapshot` set? Without it, AWS demands a snapshot identifier and refuses to proceed. Re-run the delete with the flag.

---

## What's where on disk after a fresh run

```
~/.aws/credentials                                  # Learner Lab session creds (rotates every Start Lab)
~/.aws/config                                       # region = us-east-1
~/.xml2emmet-aws/db_password                        # generated per session
~/.xml2emmet-aws/db_host                            # RDS endpoint
~/.xml2emmet-aws/rds_sg                             # RDS security group ID

/Users/I765724/Documents/xml2emmet/
├── .ebextensions/01-php.config                     # document_root /public
├── .platform/hooks/postdeploy/01-migrate.sh        # runs bin/migrate.php on every deploy
├── .platform/nginx/conf.d/elasticbeanstalk/01-router.conf  # try_files fallback
├── .ebignore                                       # ship vendor/ but skip tests/, docs/, etc.
├── .elasticbeanstalk/config.yml                    # written by eb init; gitignored
├── vendor/                                         # composer install --no-dev result (~76 KB)
└── docs/
    ├── aws-deploy.md                               # full reference, with troubleshooting
    └── aws-demo-playbook.md                        # this file
```

---

## What I'd avoid on demo day

- **Don't `composer update`** the morning of. If a transitive dep changed and `vendor/` rebuilds with a regression, you're debugging Composer at 9am. The current `vendor/` is committed-to-disk locally — leave it alone.
- **Don't change the `--platform` string** unless `aws elasticbeanstalk list-available-solution-stacks` proves the old one is gone. Even if AWS publishes `PHP 8.5`, sticking with the version `composer.json` declares (`^8.2`) is the boring, safe choice.
- **Don't enable HTTPS for the first time** the morning of. It needs CloudFront, an ACM cert, and `XML2EMMET_SECURE_COOKIE=1` — three places to break.
- **Don't add a load balancer**. Single-instance is what we tested. ALB introduces health-check config that has bitten us before and costs money.

---

## Emergency: "the demo is in 30 minutes and nothing works"

In rough order of effort:

1. **`eb deploy` once more.** Half of EB's transient failures clear on a redeploy.
2. **`eb logs --all | tail -100`.** The fix is almost always specific and named in the log.
3. **`eb terminate xml2emmet-prod --force` then start over from Step 4.** ~7 min from a working RDS. RDS deletes are slower; if RDS is fine, only EB needs rebuilding.
4. **Show the local Docker version instead.** `docker compose up -d` brings up the same app on `http://localhost:8080`. Architecture diagram still shows AWS — explain "this is the same app deployed locally for the demo because of a transient AWS Academy issue." Less impressive, but it's a working app.

The local Docker fallback exists because we built it first. Use it if you must.
