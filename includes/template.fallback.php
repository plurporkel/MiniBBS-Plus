<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title><?php echo htmlspecialchars('Fundamental Error - ' . strip_tags($this->title), ENT_QUOTES, 'UTF-8'); ?></title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            margin: 0;
            padding: 20px;
            color: #333;
        }
        h2 {
            color: #721c24;
            margin-top: 0;
        }
        .error-container {
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            border-radius: 4px;
        }
    </style>
</head>

<body>
    <div class="error-container">
        <h2><?php echo htmlspecialchars($this->title, ENT_QUOTES, 'UTF-8'); ?></h2>
        <div class="error-content">
            <?php echo $this->content; ?>
        </div>
    </div>
</body>
</html>