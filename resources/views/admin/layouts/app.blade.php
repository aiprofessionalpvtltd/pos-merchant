<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Admin Panel')</title>

    @include('admin.layouts.header_files')

 </head>
<body data-theme="default" data-layout="fluid" data-sidebar-position="left" data-sidebar-layout="default">
<div class="wrapper">
    @include('admin.layouts.sidebar')
    <div class="main">
        @include('admin.layouts.header')
        <main class="content">
            @yield('content')
        </main>
            @include('admin.layouts.footer')
            @include('admin.layouts.footer_files')
    </div>
</div>



 </body>
</html>
