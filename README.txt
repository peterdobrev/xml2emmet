Факултетен номер : 3MI0600294
Име               : Денис Мустафа Каим
Специалност       : Компютърни системи и информационни технологии (СИ), 3. курс
Преподавател      : Милен Петров
Дисциплина        : Облачни технологии (AWS)

Проект            : xml2emmet — AWS разширение
Репо              : https://github.com/peterdobrev/xml2emmet
Бранч             : feat/aws-ec2-rds-s3
Съвместно с       : Петър Добрев (основно приложение в курса по WEB)

Използвани AWS услуги
---------------------
  EC2 t3.micro          — уеб сървър (Apache + PHP 8.2), лекция 7
  RDS MySQL 8.0         — релационна база данни, лекция 8
  S3                    — JSON export на история + presigned URL, лекция 4
  VPC + Security Groups — мрежова изолация, лекции 5-6
  SSM Parameter Store   — съхранение на credentials, лекция 15
  CloudWatch Logs       — логове на приложението, лекция 16
  CloudFormation        — IaC за цялата инфраструктура

Деплой
------
  Вижте deploy/cloudformation.yml и docs/aws-deployment.md

URL на приложението   
-------------------
  http://54.86.209.220/app.html
  (достъпен докато AWS Academy Learner Lab сесията е активна)
