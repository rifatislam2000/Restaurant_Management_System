<?php
include("includes/dbconfig.php");

if (isset($_POST['login'])) {

  $email = $_POST['email'];
  $password = $_POST['password'];

  $sql = "SELECT * FROM users WHERE email = '$email'";
  $result = mysqli_query($conn, $sql);

  if (!$result) {
    echo "Error: " . mysqli_error($conn);
  } else {

    if (mysqli_num_rows($result) > 0) {

      $row = mysqli_fetch_assoc($result);

      if ($row['password'] == $password) {

        session_start();

        $_SESSION['user_id'] = $row['user_id'];
        $_SESSION['full_name'] = $row['full_name'];
        $_SESSION['username'] = $row['username'];
        $_SESSION['role'] = $row['role'];
        $_SESSION['email'] = $row['email'];


        header("Location: Admin/dashboard.php");
        exit;
      } else {
        echo "Password is wrong";
      }
    } else {
      echo "Email not found";
    }
  }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <script src="assets/js/login.js" defer></script>
  <title>Restaurant Management System</title>
</head>

<body>

  <div class="form-body mt-4">
    <form class="row g-3" method="post">

      <div class="col-12">
        <label for="inputEmailAddress" class="form-label">Email</label>
        <input type="email" name="email" class="form-control border-3"
          id="inputEmailAddress" placeholder="jhon@example.com" required>
      </div>

      <div class="col-12">
        <label for="inputChoosePassword" class="form-label">Password</label>
        <div class="input-group" id="show_hide_password">
          <input type="password" name="password"
            class="form-control border-end-0 border-3"
            id="inputChoosePassword"
            placeholder="Enter Password" required>
        </div>
      </div>

      <div class="col-12">
        <div class="d-grid">
          <button type="submit" name="login"
            class="btn border-3 btn-primary">
            Login
          </button>
          <div class="col-12">
            <div class="text-start">
              <p class="mb-0">Don't have an account yet? <a href="auth-boxed-register.html">Sign up here</a>
              </p>
            </div>
          </div>
        </div>

    </form>
  </div>

</body>

</html>