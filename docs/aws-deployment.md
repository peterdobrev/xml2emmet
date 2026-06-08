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

> Всички команди по-долу се пускат в **AWS CloudShell** (bash терминал в браузъра).
> CloudShell е достъпен от иконата горе вляво в AWS конзолата.
> Credentials са вече конфигурирани — не се нуждаеш от `aws configure`.

---

## Стъпка 1 — Подготовка (само веднъж)

**1.1 Push на бранча от локалния компютър**

```bash
git push -u origin feat/aws-ec2-rds-s3
```

**1.2 Провери CloudShell**

В CloudShell провери че акаунтът е наред:

```bash
aws sts get-caller-identity
```

Трябва да видиш JSON с `Account` и `Arn`.

**1.3 Клонирай репото в CloudShell**

```bash
git clone -b feat/aws-ec2-rds-s3 \
  https://github.com/YOUR_USER/xml2emmet.git
cd xml2emmet
```

> Замени `YOUR_USER` с твоя GitHub username.

---

## Стъпка 2 — Deploy на CloudFormation стека

Една команда създава цялата инфраструктура.

> **Key Pair**: В AWS Academy us-east-1 съществува готов key pair `vockey` — не създавай нов.

```bash
aws cloudformation deploy \
  --template-file deploy/cloudformation.yml \
  --stack-name xml2emmet \
  --parameter-overrides \
      KeyPairName=vockey \
      DBMasterPassword=MyPassword123 \
      GithubRepoUrl=https://github.com/YOUR_USER/xml2emmet.git \
      AppBranch=feat/aws-ec2-rds-s3 \
  --region us-east-1
```

> Замени `MyPassword123` с реална парола (мин. 8 символа) и `YOUR_USER` с GitHub username.
> Изчакай ~10-12 минути — RDS отнема най-много.

Провери статуса:

```bash
aws cloudformation describe-stacks \
  --stack-name xml2emmet \
  --query 'Stacks[0].StackStatus' \
  --region us-east-1
```

Трябва да видиш `"CREATE_COMPLETE"`.

---

## Стъпка 3 — Вземи URL-а на приложението

```bash
aws cloudformation describe-stacks \
  --stack-name xml2emmet \
  --query 'Stacks[0].Outputs[?OutputKey==`AppURL`].OutputValue' \
  --output text \
  --region us-east-1
```

Отвори URL-а в браузъра → трябва да видиш login страницата.

> Ако страницата не се зарежда веднага, изчакай 2-3 мин и презареди.
> Bootstrap скриптът (инсталация на PHP, Apache, SDK, миграция) тече ~3-4 мин след EC2 start.

---

## Стъпка 4 — Провери логовете (при проблем)

**Чрез CloudWatch:**

```bash
aws logs tail /ec2/xml2emmet --follow --region us-east-1
```

**Чрез SSH от CloudShell** (ключът е вече наличен):

```bash
# Вземи Elastic IP
ELASTIC_IP=$(aws cloudformation describe-stacks \
  --stack-name xml2emmet \
  --query 'Stacks[0].Outputs[?OutputKey==`AppURL`].OutputValue' \
  --output text --region us-east-1 | sed 's|http://||')

ssh -i ~/.ssh/labsuser.pem ec2-user@$ELASTIC_IP
```

Вътре в EC2:

```bash
sudo tail -f /var/log/xml2emmet-init.log
```

---

## Стъпка 5 — Тест на приложението

1. Отвори URL-а от Стъпка 3 в браузъра
2. Регистрирай се
3. Направи няколко трансформации (XML → Emmet)
4. Виж историята (History таб)
5. Тествай S3 export от CloudShell:

```bash
# Вземи session cookie от браузъра: DevTools (F12) → Application → Cookies → xml2emmet_sid
curl -s -X POST http://<APP_URL>/api/history/export \
  -H "Cookie: xml2emmet_sid=<SESSION_COOKIE>"
```

Ще получиш JSON с `download_url` — presigned S3 URL, валиден 1 час.

---

## Стъпка 6 — Teardown (след защитата)

```bash
# Вземи bucket name
BUCKET=$(aws cloudformation describe-stacks \
  --stack-name xml2emmet \
  --query 'Stacks[0].Outputs[?OutputKey==`S3BucketName`].OutputValue' \
  --output text --region us-east-1)

# Изтрий S3 обектите (иначе CF не може да изтрие bucket-а)
aws s3 rm s3://$BUCKET --recursive

# Изтрий целия стек
aws cloudformation delete-stack --stack-name xml2emmet --region us-east-1
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
