Факултетен номер : 3MI0600294
Име               : Денис Мустафа Каим
Специалност       : Софтуерно инженерство (СИ), 3. курс
Преподавател      : проф. д-р Милен Петров
Дисциплина        : Приложно-програмни интерфейси за работа с облачни архитектури с Амазон Уеб Услуги (AWS)

Проект            : xml2emmet — AWS разширение
Репо              : https://github.com/peterdobrev/xml2emmet
Бранч             : feat/aws-ec2-rds-s3


Използвани AWS услуги
---------------------
  EC2 t3.micro          — уеб сървър (Apache + PHP 8.2)
  RDS MySQL 8.0         — релационна база данни
  S3                    — JSON export на история + presigned URL
  VPC + Security Groups — мрежова изолация
  SSM Parameter Store   — съхранение на credentials
  CloudWatch Logs       — логове на приложението
  CloudFormation        — IaC за цялата инфраструктура

Съдържание на архива
--------------------
  README.txt                   — този файл
  docs/aws-report.tex          — документация (LaTeX)
  docs/aws-report.pdf          — документация (PDF)
  docs/aws-deployment.md       — инструкции за деплой
  deploy/cloudformation.yml    — CloudFormation шаблон (IaC)
  src/Aws/HistoryExporter.php  — S3 интеграция
  src/Config.php               — конфигурация с AWS env vars
  public/index.php             — front controller с AWS routes
  composer.json                — зависимости (aws/aws-sdk-php)
  .env.example                 — пример за env конфигурация
  video.mp4                    — демо видео

AWS Academy — направени упражнения и тестове
--------------------------------------------
  AWS Academy Learner Lab [161034]        — използван за целия деплой
  AWS Academy Cloud Foundations [161029]  — Knowledge Checks: модули 1, 2, 3, 4, 7; Lab 4: Working with EBS (100/100)
  AWS Academy Cloud Architecting [161030] — Knowledge Checks: модули 2, 3, 4; Guided Lab: Exploring AWS IAM (56/56)
