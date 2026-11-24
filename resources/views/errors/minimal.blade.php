@php
    $currentAppTheme = '';
    $currentAppTheme = getUserAppTheme();
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="<?= config('CURRENT_LOCALE_DIRECTION') ?>">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="">
    <meta name="author" content="">
    <!-- Primary Meta Tags -->
    <title>@yield('title')</title>
    <meta name="title" content="@yield('title')">
    <meta name="description" content="@yield('title')">

    <!-- Open Graph / Facebook -->
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ url('/') }}">
    <meta property="og:title" content="@yield('title')">
    <meta property="og:description" content="@yield('title')">
    <meta property="og:image" content="{{ getAppSettings('logo_image_url') }}">

    <!-- Twitter -->
    <meta property="twitter:card" content="summary_large_image">
    <meta property="twitter:url" content="{{ url('/') }}">
    <meta property="twitter:title" content="@yield('title')">
    <meta property="twitter:description" content="@yield('title')">
    <meta property="twitter:image" content="{{ getAppSettings('logo_image_url') }}">

    <?= __yesset(['static-assets/packages/fontawesome/css/all.css', 'dist/css/vendorlibs.css', 'argon/css/argon.min.css', 'dist/css/app.css'], true) ?>

    <link rel="shortcut icon" href="<?= getAppSettings('favicon_image_url') ?>" type="image/x-icon">
    <link rel="icon" href="<?= getAppSettings('favicon_image_url') ?>" type="image/x-icon">

    <!-- App Theme Change -->
    <!-- App Theme: Light Only -->
</head>

<body id="page-top" class="lw-gradient-bg">

  
 
    <!-- Page Wrapper -->
    <!-- Begin Page Content -->
    <div class="lw-page-content lw-other-page-content lw-error-page-block-section">
        <section class="container text-center">
            <!-- Logo -->
            <img src="{{ getAppSettings('logo_image_url') }}" class="light-theme-logo lw-error-logo mb-5"
                alt="{{ getAppSettings('name') }}">

            <div class="row">
                <div class="col-lg-7 col-sm-12 m-auto col-xl-5">
                    <div class="lw-error-page-block d-flex justify-content-center align-items-center p-5">
                        <div class="row">
                            <div class="col-12">
                                <i class="far fa-frown fa-5x text-danger"></i>
                                <h1 class="fa-7x font-weight-bold">@yield('code')</h1>
                                <h2 class="text-dark font-weight-bold"> @yield('title')</h2>
                                <p class="my-3" >@yield('message')</p>
                                <a href="{{ url('') }}" class="btn btn-primary">{{ __tr('Back to Home') }}</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </div>
</body>

</html>
