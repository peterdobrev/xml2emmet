# Deploying xml2emmet to AWS (Elastic Beanstalk + RDS)

This guide walks through deploying the existing app to AWS using two managed services:

- **Amazon RDS for MySQL** — drop-in replacement for the `mysql` service in `docker-compose.yml`. The app already speaks MySQL via PDO (`src/Db/Db.php`); no application code changes are needed.
- **AWS Elastic Beanstalk (PHP platform)** — runs `public/index.php` on managed Apache + PHP, on top of EC2 + (optionally) Auto Scaling + CloudWatch. Deploys from a zip bundle.

The architecture mirrors `docker-compose.yml` exactly — two services, one application — but with AWS managing the infrastructure.

```
            ┌────────────────────────────────────────┐
            │ Browser                                │
            │   • SPA assets (app.html, app.css, JS) │
            │   • API calls /api/* with cookie       │
            └──────────────────┬─────────────────────┘
                               │ HTTP(S)
            ┌──────────────────▼─────────────────────┐
            │ Elastic Beanstalk environment           │
            │   single-instance, PHP 8.x on AL2023   │
            │   document_root = /public               │
            └──────────────────┬─────────────────────┘
                               │ TCP 3306, PDO/MySQL
            ┌──────────────────▼─────────────────────┐
            │ Amazon RDS for MySQL 8.0               │
            │   db.t3.micro, single-AZ              │
            └────────────────────────────────────────┘
```

> **Cost note (read first):** the default Beanstalk environment provisions an **Application Load Balancer**, which is **not** Free Tier (~\$16–22/mo). This guide uses a **Single-Instance** environment instead — it skips the ALB and stays within the EC2 Free Tier (`t3.micro`, 750 hr/month). RDS `db.t3.micro` is also Free Tier for 12 months.

---

## Prerequisites

- AWS account with the IAM permissions to create EB applications, EC2 instances, security groups, and RDS instances. The simplest setup for a uni demo is an IAM user with `AdministratorAccess`; tighten later.
- AWS CLI v2: <https://docs.aws.amazon.com/cli/latest/userguide/getting-started-install.html>
- Elastic Beanstalk CLI: <https://docs.aws.amazon.com/elasticbeanstalk/latest/dg/eb-cli3-install.html> (`pip install awsebcli` or `brew install awsebcli`)
- A region. **AWS Academy Learner Lab pins you to `us-east-1`** — this guide now uses that. If you're on a normal AWS account, substitute your own.
- `composer` locally to vendor dependencies before bundling.

```bash
aws configure          # access key + secret + region
aws sts get-caller-identity   # sanity check
```

---

## 1. Add EB platform configuration to the repo

EB needs three things from this repo it doesn't have yet:

1. A `phpini` setting telling Apache that `public/` is the document root.
2. A `postdeploy` hook that runs `bin/migrate.php` against RDS so the schema is applied on first deploy.
3. The vendored Composer dependencies (`vendor/`) included in the zip — EB's PHP platform on Amazon Linux 2023 does **not** run `composer install` for you.

> Two facts that drive these choices, both verified in the code:
>
> - `public/index.php:3` and `bin/migrate.php:3` both `require __DIR__ . '/../vendor/autoload.php'` — without `vendor/` in the bundle, the app crashes immediately.
> - `bin/migrate.php` is idempotent: it tracks applied files in a `schema_migrations` table (`bin/migrate.php:13–18`) and skips files already applied. Safe to run on every deploy.

### 1.1 `.ebextensions/01-php.config`

Create the file:

```bash
mkdir -p .ebextensions
cat > .ebextensions/01-php.config <<'YAML'
option_settings:
  aws:elasticbeanstalk:container:php:phpini:
    document_root: /public
    memory_limit: 256M
    display_errors: "Off"
YAML
```

Without `document_root: /public`, requests to `/app.html` 404 because Apache serves the application root, where there's no `index.html` and `public/` is just a subdirectory.

### 1.2 `.platform/hooks/postdeploy/01-migrate.sh`

```bash
mkdir -p .platform/hooks/postdeploy
cat > .platform/hooks/postdeploy/01-migrate.sh <<'BASH'
#!/usr/bin/env bash
set -euo pipefail
# EB postdeploy cwd is the deployed app dir (/var/app/current).
# Environment properties set in the EB console are exported here.
echo "Running database migrations against ${XML2EMMET_DB_HOST}:${XML2EMMET_DB_PORT}/${XML2EMMET_DB_NAME}"
php bin/migrate.php
BASH
chmod +x .platform/hooks/postdeploy/01-migrate.sh
```

Notes:

- AL2023 platforms released after April 2022 auto-grant execute permission to hook scripts, but `chmod +x` is harmless and helps if you ever inspect the zip.
- Hooks run **as root** with `cwd = /var/app/current`, and EB env properties are exposed as real environment variables — exactly what `config/config.php` reads via `getenv()`.
- `bin/migrate.php` reads `XML2EMMET_DB_USER`/`XML2EMMET_DB_PASS` and will throw if they are unset (`src/Config.php:19–23`). That's the desired behavior — a misconfigured environment fails the deploy loudly instead of booting a broken app.

### 1.3 `.platform/nginx/conf.d/elasticbeanstalk/01-router.conf`

The AL2023 PHP platform runs **nginx + php-fpm**, not Apache. nginx by default only routes requests to PHP for files that exist; an unknown path like `/api/auth/register` returns nginx's own 404 without ever reaching `index.php`. We need a single-line override:

```bash
mkdir -p .platform/nginx/conf.d/elasticbeanstalk
cat > .platform/nginx/conf.d/elasticbeanstalk/01-router.conf <<'NGINX'
# Included inside the default EB server block. The default config sets
# `root /var/app/current/public;` and serves $uri directly; this adds a
# fallback so non-file paths route to index.php for the PHP router to handle.
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
NGINX
```

> **Path matters here.** Files under `.platform/nginx/conf.d/` are loaded into the nginx **`http {}`** block, where `location` directives are syntax errors. Files under `.platform/nginx/conf.d/elasticbeanstalk/` are loaded **inside the default `server {}`** block, where `location` works. If you put the file in the wrong place, the deploy fails with `[emerg] "location" directive is not allowed here`.

### 1.4 `.ebignore` — stop EB from re-uploading junk

```bash
cat > .ebignore <<'EOF'
/.git
/.idea
/.vscode
/.phpunit.cache
/.playwright-mcp
/.worktrees
/.superpowers
/scratch
/tests
/docs
/.dockerignore
docker-compose.yml
EOF
```

Importantly, **do not** ignore `vendor/` — we want it shipped. Also note: by default EB uses `git ls-files` to build the bundle, which excludes `vendor/` if it's gitignored. `.ebignore` overrides that and tells EB to use the working tree instead.

### 1.5 Vendor dependencies for the bundle

```bash
composer install --no-dev --optimize-autoloader
```

`--no-dev` keeps PHPUnit and friends out of the bundle. `--optimize-autoloader` turns the PSR-4 map into a classmap for a small startup win. The repo's `composer.json` declares only `php: ^8.2`, so this is fast and produces a small `vendor/`.

> If you'd rather have EB run Composer for you (cleaner repo, slower deploys, fragile if Packagist is down), put this prebuild hook in instead and remove `vendor/` from the bundle:
>
> ```bash
> # .platform/hooks/prebuild/01-composer.sh
> #!/usr/bin/env bash
> set -euo pipefail
> command -v composer >/dev/null || curl -sS https://getcomposer.org/installer | php && mv composer.phar /usr/local/bin/composer
> composer install --no-dev --optimize-autoloader
> ```
>
> For a uni demo, **vendored is the safer call** — your deploy doesn't depend on a network round-trip to Packagist mid-presentation.

### 1.6 Commit the EB scaffolding

```bash
git add .ebextensions .platform .ebignore
git commit -m "chore(aws): elastic beanstalk platform config + postdeploy migration hook"
```

---

## 2. Create the RDS database

You can do this in the console (faster to click through), or via CLI. CLI shown here so the doc is reproducible.

### 2.1 Pick a DB password and stash it locally

```bash
DB_PASSWORD=$(openssl rand -base64 24 | tr -d '+/=' | cut -c1-20)
echo "$DB_PASSWORD"   # write this down — you'll paste it into the EB env later
```

### 2.2 Create the RDS instance (Free Tier sized)

```bash
aws rds create-db-instance \
  --db-instance-identifier xml2emmet-db \
  --db-instance-class db.t3.micro \
  --engine mysql \
  --engine-version 8.0 \
  --allocated-storage 20 \
  --storage-type gp2 \
  --master-username admin \
  --master-user-password "$DB_PASSWORD" \
  --db-name xml2emmet \
  --backup-retention-period 1 \
  --no-multi-az \
  --publicly-accessible \
  --no-deletion-protection
```

Why these flags:

- `db.t3.micro`, `gp2`, `--allocated-storage 20` — the Free Tier shape.
- `--db-name xml2emmet` — pre-creates the database so `bin/migrate.php` can connect straight to it. Matches the schema used in `src/schema/001_init.sql` (`CREATE TABLE users …` — no `CREATE DATABASE` in the schema files).
- `--publicly-accessible` plus a default-VPC security group makes the connectivity story simple for a demo. **For a real deployment** you'd put RDS in a private subnet and only allow the EB SG. Mention this trade-off in your write-up.
- `--no-multi-az`, `--backup-retention-period 1` — minimal cost. (Backups can't be 0 if you want point-in-time recovery; 1 is the cheapest non-zero.)

Wait until it's available (~5–8 min):

```bash
aws rds wait db-instance-available --db-instance-identifier xml2emmet-db
DB_HOST=$(aws rds describe-db-instances \
  --db-instance-identifier xml2emmet-db \
  --query 'DBInstances[0].Endpoint.Address' --output text)
echo "RDS endpoint: $DB_HOST"
```

### 2.3 Smoke-test connectivity from your laptop

This is the fastest way to catch a security-group or VPC misconfiguration **before** EB enters the picture.

```bash
docker run --rm -it mysql:8.0 mysql \
  -h "$DB_HOST" -P 3306 -u admin -p"$DB_PASSWORD" \
  -e "SHOW DATABASES;"
```

If that hangs, your default VPC's RDS security group isn't allowing inbound 3306 from your laptop's IP. Fix it in the console: **RDS → Databases → xml2emmet-db → Connectivity & security → VPC security groups → Inbound rules → Edit → Add MySQL/Aurora from "My IP"**.

### 2.4 Apply the schema once from your laptop (optional but recommended)

You *can* let the EB postdeploy hook apply migrations on first deploy. But running them once from your laptop first means you can debug schema issues without EB in the loop.

```bash
XML2EMMET_DB_HOST="$DB_HOST" \
XML2EMMET_DB_PORT=3306 \
XML2EMMET_DB_NAME=xml2emmet \
XML2EMMET_DB_USER=admin \
XML2EMMET_DB_PASS="$DB_PASSWORD" \
php bin/migrate.php
```

Expected output:

```
apply 001_init.sql … ok
```

The hook on EB will then print `skip 001_init.sql (already applied)` on first deploy, which is exactly the idempotent behavior you want.

---

## 3. Create the Elastic Beanstalk environment

### 3.1 Initialize the EB application

From the project root:

```bash
eb init xml2emmet \
  --platform "PHP 8.2 running on 64bit Amazon Linux 2023" \
  --region us-east-1
```

This writes `.elasticbeanstalk/config.yml` and asks once whether to set up CodeCommit (say no). The platform string changes over time — if EB rejects it, run `eb platform list` and pick the latest PHP-on-AL2023 line.

### 3.2 Create the environment (single-instance — no ALB!)

```bash
eb create xml2emmet-prod \
  --single \
  --instance-type t3.micro \
  --envvars XML2EMMET_DB_HOST="$DB_HOST",XML2EMMET_DB_PORT=3306,XML2EMMET_DB_NAME=xml2emmet,XML2EMMET_DB_USER=admin,XML2EMMET_DB_PASS="$DB_PASSWORD",XML2EMMET_SECURE_COOKIE=0,XML2EMMET_DEBUG=0
```

Flag-by-flag:

- `--single` — creates a single-instance environment with **no load balancer**. Critical for staying in Free Tier. Drop this flag and you'll silently provision an ALB.
- `--instance-type t3.micro` — Free Tier eligible.
- `--envvars` — these are exposed both to the running PHP process *and* to the postdeploy hook script. `config/config.php` reads `XML2EMMET_*` via `getenv()`, so the variable names must match exactly.
- `XML2EMMET_SECURE_COOKIE=0` — leave this `0` until you put the environment behind HTTPS (see §5). With it set to `1`, the session cookie has `Secure` and the browser refuses to send it over HTTP, breaking login.

Initial environment creation takes ~5–10 minutes. `eb create` streams events; you'll see Auto Scaling group and EC2 instance creation, then the deploy of your code (which runs the postdeploy migration hook).

### 3.3 Open the security-group hole between EB and RDS

The single-instance EB environment runs in your default VPC with its own security group. RDS is publicly accessible and gated by *its* security group. By default, RDS only allows your laptop IP from §2.3 — not the EB instance.

Get the EB instance's security group, then allow it inbound on the RDS SG:

```bash
EB_SG=$(aws ec2 describe-instances \
  --filters "Name=tag:elasticbeanstalk:environment-name,Values=xml2emmet-prod" \
  --query 'Reservations[].Instances[].SecurityGroups[].GroupId' --output text)
RDS_SG=$(aws rds describe-db-instances \
  --db-instance-identifier xml2emmet-db \
  --query 'DBInstances[0].VpcSecurityGroups[0].VpcSecurityGroupId' --output text)
echo "EB SG: $EB_SG  →  RDS SG: $RDS_SG"
aws ec2 authorize-security-group-ingress \
  --group-id "$RDS_SG" \
  --protocol tcp --port 3306 \
  --source-group "$EB_SG"
```

> **This is the single most common live-demo failure mode.** The app will deploy, the homepage loads from `app.html`, but every `/api/*` call returns 500 with a PDO connection error in the EB logs because RDS rejects the connection. If you're rehearsing, deliberately break this rule once so you recognize the symptom.

After running the command above, redeploy so the postdeploy hook actually reaches RDS:

```bash
eb deploy
```

You should see `apply 001_init.sql … ok` (or `skip … already applied` if you ran §2.4) in the deploy output.

### 3.4 Verify the environment is healthy

```bash
eb status
eb open    # opens the env URL in your browser
```

The URL will be `http://xml2emmet-prod.<random>.<region>.elasticbeanstalk.com`. Visit `/app.html` and you should see the SPA. Register a user, run a transform — same flow as the local Docker run.

---

## 4. Demo script — the 5-minute version

A presentation-friendly sequence:

1. **Show the architecture diagram** at the top of this doc. One slide.
2. `eb status` — show the environment's URL and health.
3. **Browse to `/app.html`** in the EB URL. Register `demo_user`. Live transform: `<div class="hero"><h1>Hi</h1></div>` → `div[class="hero"]>h1{Hi}`.
4. `eb logs --all | tail -40` — show the request log lines (`public/index.php:91` writes `[reqid] 200 12ms POST /api/transform` to `error_log`, which EB streams to CloudWatch). Demonstrates the managed log-aggregation story.
5. **AWS Console → RDS → xml2emmet-db** — show Performance Insights or the CloudWatch CPU graph. "I get this for free."
6. **AWS Console → Elastic Beanstalk → xml2emmet-prod** — show the **Configuration** page. Highlight that scaling, instance type, and env vars are click-edits, not file edits. Worth two sentences in the write-up.
7. `eb deploy` (after a tiny edit, e.g. adding a CSS rule) — show a rolling redeploy and the postdeploy migration log line.

---

## 5. Optional: HTTPS

For a course demo, plain HTTP is acceptable. If your rubric scores HTTPS, the cheapest path on a single-instance EB environment is:

1. Buy or own a domain.
2. Request an ACM certificate in the same region (free).
3. Point a Route 53 record at your EB instance.
4. Use **AWS CloudFront** in front of the EB single-instance URL (free SSL, free certificate via ACM, generous Free Tier).
5. Set `XML2EMMET_SECURE_COOKIE=1` in EB env vars and redeploy.

Adding an ALB to terminate TLS directly is technically simpler, but it leaves Free Tier (~\$16–22/mo). CloudFront is the cheaper route.

---

## 5b. Running the environment for ~12 days (Free Tier sanity check)

If the plan is "deploy now to test, leave it running, come back for the real demo in ~12 days, then tear down" — **yes, this is what Free Tier is built for**, and 12 days fits comfortably under every monthly limit. But there are real cost traps that turn a free demo into a \$30 surprise. Read this before clicking anything.

### The 12-day math

| Resource | Free Tier (per month) | 12 days continuous |
|---|---|---|
| EC2 `t3.micro` Linux (the EB instance) | 750 hours | 12 × 24 = **288 h** ✅ |
| RDS `db.t3.micro` MySQL single-AZ | 750 hours | **288 h** ✅ |
| RDS gp2 storage | 20 GB-months | ~8 GB-months ✅ |
| Data transfer out to internet | 100 GB | trivial for a uni demo ✅ |

### Cost traps to avoid

1. **Application Load Balancer** is **not Free Tier** at all (~\$0.0225/h + LCU charges, ~\$6.50 minimum over 12 days even with zero traffic). The default `eb create` provisions one. **Always use `--single`.** This guide does.
2. **NAT Gateway** (~\$0.045/h + per-GB, ~\$13 over 12 days). Avoided by keeping RDS in the default VPC's public subnets, as in §2.
3. **Elastic IP not attached to a running instance** (\$0.005/h while detached). Single-instance EB doesn't allocate one — don't add one manually.
4. **Stopping RDS to "save money"**: storage still bills, and **AWS auto-restarts a stopped RDS instance after 7 days**. Don't bother — leave it running, Free Tier covers it.
5. **Account is older than 12 months**: the EC2/RDS Free Tier is the *12-month* tier, not Always Free. Check Console → top-right account menu → My Account → Account Created. If it's older than 12 months, `t3.micro` runs at on-demand rates (~\$0.01/h each, ~\$3 each over 12 days, still cheap, not free).
6. **`XML2EMMET_DEBUG=1` left on**: the env var defaults to `0` and our `eb create` line sets it explicitly. Leave it `0` — `XML2EMMET_DEBUG=1` includes stack traces in 5xx responses (`public/index.php:81`), fine for a class but bad to leave facing the internet for 12 days.

### Belt-and-braces: a billing alarm at \$1

The single most useful 5-minute step in the whole process. Most of it lives in the human-only Billing console because billing metrics require an opt-in checkbox before CloudWatch will even *receive* the data.

**Manual steps (Console only — there is no CLI for the opt-in):**

1. Sign in → top-right account menu → **Billing and Cost Management** → **Billing preferences** → enable both:
   - **"Receive AWS Free Tier alerts"** (sends emails when you hit 85% of any Free Tier quota)
   - **"Receive CloudWatch billing alerts"** (publishes the `EstimatedCharges` metric in `us-east-1`)
   - Add an email address.
2. Wait ~10 minutes for the metric to start publishing.

**Then the alarm itself (CLI is fine):**

```bash
# Billing metrics live ONLY in us-east-1 regardless of where your resources run.
aws sns create-topic --name billing-alerts --region us-east-1
# Subscribe your email; confirm the email AWS sends.
aws sns subscribe \
  --topic-arn arn:aws:sns:us-east-1:<your-account-id>:billing-alerts \
  --protocol email --notification-endpoint your.email@example.com \
  --region us-east-1

# Alarm: page me when estimated charges go over $1.
aws cloudwatch put-metric-alarm \
  --alarm-name xml2emmet-billing-1usd \
  --alarm-description "Estimated AWS charges > 1 USD" \
  --metric-name EstimatedCharges --namespace AWS/Billing \
  --statistic Maximum --period 21600 --evaluation-periods 1 \
  --threshold 1 --comparison-operator GreaterThanThreshold \
  --dimensions Name=Currency,Value=USD \
  --alarm-actions arn:aws:sns:us-east-1:<your-account-id>:billing-alerts \
  --region us-east-1
```

\$1, not \$5 or \$10. If the alarm fires you've found the misconfiguration the same day, not at the end of the month.

### Three patterns for "leave it running for ~12 days"

- **A — Leave it on (recommended).** Single-instance EB + RDS Free Tier covers 24/7 for 12 days. Stable URL for slides. Iterate with `eb deploy` whenever (~60–90 s, no extra cost). Set the calendar reminder for tear-down day.
- **B — Snapshot + delete during quiet stretches.** Snapshot RDS, delete instance, terminate EB env. Restore + re-create when needed. Provably zero cost while idle, but you redo §2 and §3, and the URL changes.
- **C — Stop RDS during quiet periods.** Worst of the three: storage still bills, auto-restarts after 7 days, saves maybe \$1–2 over A. Skip.

For a uni demo: **Pattern A**.

### Pre-deploy checklist (literally before `eb create`)

- [ ] Account is < 12 months old (or you're OK paying ~\$6 over 12 days)
- [ ] Billing alerts opted in, alarm at \$1 created and email confirmed
- [ ] You've decided your region (this guide uses `us-east-1`, which Learner Lab forces anyway)
- [ ] `eb create` will be run with `--single` (no ALB!)
- [ ] RDS will be `db.t3.micro` (no `db.m5.*` — those are NOT Free Tier)
- [ ] Calendar reminder set for tear-down day (§6)

---

## 6. Tear-down (run this after the demo!)

If you forget this step, the EC2 instance keeps billing past Free Tier hours and RDS storage charges accrue.

```bash
eb terminate xml2emmet-prod --force
aws rds delete-db-instance \
  --db-instance-identifier xml2emmet-db \
  --skip-final-snapshot
aws rds wait db-instance-deleted --db-instance-identifier xml2emmet-db
```

`eb terminate` removes the EC2 instance, EB environment, and the EB-created security group. The EB application itself remains so you can `eb create` again later for a re-demo; remove it with `eb terminate --all` if you're done for good.

---

## Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| `/app.html` returns 404 | `document_root` not set; Apache is serving project root | Verify `.ebextensions/01-php.config` is in the bundle and the value is `/public` (with leading slash) |
| Static files (`/app.html`, `/app.css`) work, but every `/api/*` returns nginx's HTML 404 | AL2023 PHP platform uses nginx; default config doesn't fall through to `index.php` for unknown paths | §1.3 — add the `try_files` snippet at `.platform/nginx/conf.d/elasticbeanstalk/01-router.conf` |
| Deploy fails with `[emerg] "location" directive is not allowed here` | nginx snippet was put in `.platform/nginx/conf.d/` (http block) instead of `.platform/nginx/conf.d/elasticbeanstalk/` (server block) | Move the file to the `elasticbeanstalk/` subdirectory |
| First deploy fails with `Connection timed out` from `bin/migrate.php` | EB SG not yet allowed in RDS SG | §3.3 — authorize from the EB instance's SG, then `eb deploy` once more to re-run the postdeploy hook |
| Homepage works, all `/api/*` return 500 | EB SG not allowed in RDS SG | §3.3 — `authorize-security-group-ingress` |
| `eb deploy` fails with `Missing required env var: XML2EMMET_DB_USER` | Env vars not set on the environment | `eb setenv XML2EMMET_DB_USER=admin XML2EMMET_DB_PASS=…` |
| Login succeeds but every later request returns 401 | `XML2EMMET_SECURE_COOKIE=1` while serving over HTTP | Set it back to `0` until you have HTTPS in front |
| Deploy hangs at "Updating environment" for >15 min | Hook script has a non-zero exit — usually the migration failing because RDS isn't reachable | `eb logs` and look for the postdeploy script output; fix connectivity, then `eb deploy` again |
| `composer install` not found on deploy | Using the prebuild Composer hook variant without internet egress from the EB instance | Either fix the egress (default VPC has it via the IGW) or switch back to the vendored variant — see §1.5 |
| `eb create` fails with `AccessDenied` on IAM role/profile | Trying to create EB on Learner Lab without overriding service role and instance profile | Add `--service-role LabRole --instance_profile LabInstanceProfile` to `eb create` |
| `eb` returns `ExpiredToken` after a few hours | Learner Lab session expired (4-hour timer) | In Canvas: End Lab → Start Lab → AWS Details → CLI Show → re-paste into `~/.aws/credentials` |
| `eb create` fails immediately with platform-not-found | Platform name string is stale | `eb platform list` and use the current PHP-on-AL2023 entry |

---

## What this proves about the architecture

Things to call out in the write-up that the demo actually demonstrates:

- **Stateless compute, stateful storage.** EB instances are cattle: the postdeploy hook proves that *any* fresh instance can rebuild itself from the source bundle and a populated RDS, no manual configuration. If the EC2 instance dies, EB replaces it; the data survives in RDS.
- **Configuration over code.** The same `config/config.php` boots locally against Docker MySQL and remotely against RDS, with no code changes — just different env values. EB's environment-property mechanism is the production analogue of `docker-compose.yml`'s `environment:` block.
- **Idempotent migrations.** `bin/migrate.php` running on every deploy is the standard "deploy is the migration" pattern. Worth a sentence in the write-up — it's not unique to this project but it's a real engineering choice the schema design enables.
- **Failure isolation.** Restarting the EB instance is harmless; restarting RDS is harmless; the two can be operated independently. That's the whole reason to split them.
