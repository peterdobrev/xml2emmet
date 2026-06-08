# AWS Deployment Guide — EC2 + RDS MySQL + S3

## Архитектура

```
Интернет
    │
    ▼
[Elastic IP]
    │  :80
    ▼
[EC2 t3.micro]                ← публична подмрежа
  Amazon Linux 2023
  Apache + PHP 8.2
    │  :3306
    ▼
[RDS MySQL 8.0 db.t3.micro]   ← частна подмрежа (2 AZ)
    │
    │  S3 PutObject / presigned GetObject
    ▼
[S3 Bucket]                   ← JSON export на история → presigned URL (1 час)
```

## Използвани AWS услуги

| Услуга | Лекция | Роля |
|--------|--------|------|
| **Amazon EC2** | 7 | Сървър за PHP приложението (t3.micro, Amazon Linux 2023) |
| **Amazon RDS MySQL 8.0** | 8 | Релационна база данни — потребители, правила, история |
| **Amazon S3** | 4 | JSON файлове с история; сваляне чрез presigned URL |
| **Amazon VPC** | 5–6 | Мрежова изолация — публична мрежа за EC2, частна за RDS |
| **Security Groups** | 5–6 | EC2: порт 80+22; RDS: порт 3306 само от EC2 |
| **SSM Parameter Store** | 15 | DB credentials и S3 bucket; вземат се при boot |
| **Amazon CloudWatch Logs** | 16 | Apache и PHP грешки → `/ec2/xml2emmet` |

---

## Стъпка 0 — Настройка за AWS Academy Learner Lab

> Тези стъпки важат само за AWS Academy. При личен акаунт използвай `aws configure`.

**0.1 Стартирай лаба**

1. Влез в [AWS Academy](https://awsacademy.instructure.com) → **Learner Lab**
2. Натисни **Start Lab** (горе вляво) — изчакай докато кръгчето стане зелено (~1 мин)
3. Натисни **AWS Details** → **AWS CLI**
4. Копирай блока с credentials — изглежда така:

```
[default]
aws_access_key_id=ASIA...
aws_secret_access_key=xxxx...
aws_session_token=xxxx... (много дълъг токен)
```

**0.2 Постави credentials (Windows)**

Отвори `%USERPROFILE%\.aws\credentials` (създай файла ако не съществува) и постави блока:

```
[default]
aws_access_key_id=ASIA...
aws_secret_access_key=xxxx...
aws_session_token=xxxx...
```

После задай региона:

```powershell
aws configure set region us-east-1
```

**0.3 Провери**

```powershell
aws sts get-caller-identity
```

Трябва да видиш JSON с `Account`, `UserId`, `Arn`.

> **Важно:** Credentials изтичат след ~4 часа. При `ExpiredTokenException` повтори стъпки 0.1–0.2.

---

## Стъпка 1 — Подготовка

**1.1 Обнови зависимостите локално**

```powershell
cd xml2emmet
composer require aws/aws-sdk-php:^3.318
git add composer.json composer.lock
git commit -m "chore: add aws/aws-sdk-php"
```

**1.2 Push на бранча**

```powershell
git push -u origin feat/aws-ec2-rds-s3
```

**1.3 Създай EC2 Key Pair**

AWS Console → EC2 → **Key Pairs** → **Create key pair**
- Name: `xml2emmet-key`
- Format: `.pem`
- Запази `.pem` файла — нужен е за SSH

---

## Стъпка 2 — Deploy на CloudFormation стека

Една команда създава цялата инфраструктура:

```powershell
aws cloudformation deploy `
  --template-file deploy/cloudformation.yml `
  --stack-name xml2emmet `
  --parameter-overrides `
      KeyPairName=xml2emmet-key `
      DBMasterPassword=MyPassword123 `
      GithubRepoUrl=https://github.com/YOUR_USER/xml2emmet.git `
      AppBranch=feat/aws-ec2-rds-s3
```

> Изчакай ~10-12 минути. RDS отнема най-много.

Провери статуса:

```powershell
aws cloudformation describe-stacks `
  --stack-name xml2emmet `
  --query 'Stacks[0].StackStatus'
```

Трябва да видиш `"CREATE_COMPLETE"`.

---

## Стъпка 3 — Вземи URL-а

```powershell
aws cloudformation describe-stacks `
  --stack-name xml2emmet `
  --query 'Stacks[0].Outputs[?OutputKey==`AppURL`].OutputValue' `
  --output text
```

Отвори URL-а в браузъра → трябва да видиш login страницата.

> Ако страницата не се зарежда веднага, изчакай 2-3 мин — bootstrap скриптът все още може да тече.

---

## Стъпка 4 — Провери логовете (при проблем)

**Чрез CloudWatch:**
```powershell
aws logs tail /ec2/xml2emmet --follow
```

**Чрез SSH:**
```powershell
# Вземи SSH командата от outputs
aws cloudformation describe-stacks `
  --stack-name xml2emmet `
  --query 'Stacks[0].Outputs[?OutputKey==`SSHCommand`].OutputValue' `
  --output text

# После:
ssh -i xml2emmet-key.pem ec2-user@<ELASTIC_IP>
sudo tail -f /var/log/xml2emmet-init.log
```

---

## Стъпка 5 — Тест на приложението

1. Регистрирай се с потребител
2. Направи няколко трансформации (XML → Emmet)
3. Виж историята (History таб)
4. Тествай S3 export:

```powershell
# Вземи session cookie от браузъра (DevTools → Application → Cookies)
curl -X POST http://<APP_URL>/api/history/export `
  -H "Cookie: xml2emmet_sid=<SESSION_COOKIE>"
```

Ще получиш JSON с `download_url` — presigned S3 URL, валиден 1 час.

---

## Стъпка 6 — Teardown (след защитата)

```powershell
# Изтрий S3 обектите първо
$BUCKET = aws cloudformation describe-stacks `
  --stack-name xml2emmet `
  --query 'Stacks[0].Outputs[?OutputKey==`S3BucketName`].OutputValue' `
  --output text
aws s3 rm s3://$BUCKET --recursive

# Изтрий стека
aws cloudformation delete-stack --stack-name xml2emmet
```

---

## Разлика от Петър (Elastic Beanstalk + RDS)

| | Петър (EBS + RDS) | Този проект (EC2 + RDS + S3) |
|---|---|---|
| Compute | Elastic Beanstalk управлява EC2 | EC2 директно — Apache, PHP, env vars |
| Database | RDS MySQL | RDS MySQL |
| Storage | няма | **S3** — JSON export на история |
| Credentials | .ebextensions | **SSM Parameter Store** |
| IaC | .ebextensions YAML | **CloudFormation** |
