<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class NormControlController extends Controller
{
    public function index()
    {
        return view('norm-control.index');
    }
}
