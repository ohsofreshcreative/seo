{{-- Szkielet HTML panelu: bez wp_head() — żadnych skryptów motywu marketingowego, CDN ani trackerów. --}}
<!doctype html>
<html lang="pl" class="h-full bg-slate-50">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <meta name="referrer" content="same-origin">
  <title>@yield('title', 'Panel') · Whack-a-mole</title>
  @vite(['resources/css/panel.css', 'resources/js/panel.js'])
</head>

<body class="h-full font-sans text-slate-800 antialiased">
  @yield('body')
</body>

</html>
