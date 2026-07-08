<h1>Students List</h1>
<ul>
@foreach($students as $student)
    <li>{{ $student->name }} - {{ $student->email }} - {{ $student->phone }}</li>
@endforeach
</ul>