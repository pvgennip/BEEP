@extends('layouts.app')
 
@section('page-title') {{ __('crud.management', ['item'=>__('general.device')]) }}
@endsection

@section('content')

			
	@component('components/box')
		@slot('title')
			{{ __('crud.overview', ['item'=>__('general.devices')]) }}
		@endslot

		@slot('action')
			@permission('sensor-create')
	            <a class="btn btn-primary" href="{{ route('devices.create') }}"><i class="fa fa-plus"></i> {{ __('crud.add_a', ['item'=>__('general.device')]) }}</a>
	            @endpermission
		@endslot

		@slot('$bodyClass')
		@endslot

		@slot('body')

		<script type="text/javascript">
            $(document).ready(function() {
                $("#table-sensors").DataTable(
                    {
                    "language": 
                        @php
                            echo File::get(public_path('js/datatables/i18n/'.LaravelLocalization::getCurrentLocaleName().'.lang'));
                        @endphp
                    ,
                    "order": 
                    [
                        [ 0, "desc" ]
                    ],
                });
            });

            function fallbackCopyTextToClipboard(text) {
			  var textArea = document.createElement("textarea");
			  textArea.value = text;
			  textArea.style.position="fixed";  //avoid scrolling to bottom
			  document.body.appendChild(textArea);
			  textArea.focus();
			  textArea.select();

			  try {
			    var successful = document.execCommand('copy');
			    var msg = successful ? 'successful' : 'unsuccessful';
			    console.log('Fallback: Copying text command was ' + msg);
			  } catch (err) {
			    console.error('Fallback: Oops, unable to copy', err);
			  }

			  document.body.removeChild(textArea);
			}
			function copyTextToClipboard(text) {
			  if (!navigator.clipboard) {
			    fallbackCopyTextToClipboard(text);
			    return;
			  }
			  navigator.clipboard.writeText(text).then(function() {
			    console.log('Async: Copying to clipboard was successful!');
			  }, function(err) {
			    console.error('Async: Could not copy text: ', err);
			  });
			}
        </script>

			<table id="table-sensors" class="table table-striped">
				<thead>
					<tr>
						<th>{{ __('crud.id') }}</th>
						<th>Sticker</th>
						<th>{{ __('crud.name') }}</th>
						<th>{{ __('crud.type') }}</th>
						<th>DEV EUI ({{ __('crud.key') }}) / Hardware ID</th>
						<th style="min-width: 150px;">Last seen</th>
						<th><img src="/img/icn_bat.svg" style="width: 20px;"></th>
						<th>Hardware version</th>
						<th style="min-width: 150px;">Firmware version</th>
						<th>Inerval (min) / ratio</th>
						<th>{{ __('general.User') }} / {{ __('beep.Hive') }}</th>
						<th>Last downlink result</th>
						<th style="min-width: 100px;">{{ __('crud.actions') }}</th>
					</tr>
				</thead>
				<tbody>
					@foreach ($sensors as $key => $device)
					<tr>
						<td>{{ $device->id }}</td>
						<td><button onclick="copyTextToClipboard('{{ $device->name }}\r\n{{ $device->hardware_id }}');">Copy</button></td>
						<td>{{ $device->name }}</td>
						<td><label class="label label-default">{{ $device->type }}</label></td>
						<td>{{ $device->key }} / {{ $device->hardware_id }}</td>
						<td>{{ $device->last_message_received }}</td>
						<td>{{ isset($device->battery_voltage) ? $device->battery_voltage.' V' : '' }}</td>
						<td>{{ $device->hardware_version }}</td>
						<td>{{ $device->firmware_version }} @if(isset($device->datetime)) ({{ $device->datetime_offset_sec > 0 ? '+'.$device->datetime_offset_sec : $device->datetime_offset_sec }} sec)<br>{{ $device->datetime }}@endif</td>
						<td>{{ $device->measurement_interval_min }} / {{ $device->measurement_transmission_ratio }} @if(isset($device->measurement_interval_min)) (=send 1x/{{ $device->measurement_interval_min * max(1,$device->measurement_transmission_ratio) }}min) @endif</td>
						<td>{{ $device->user->name }} / {{ isset($device->hive) ? $device->hive->name : '' }}</td>
						<td style="max-width: 200px; max-height: 60px; overflow: hidden;" title="{{ $device->last_downlink_result }}">{{ $device->last_downlink_result }}</td>
						<td>
							<!-- <a class="btn btn-default" href="{{ route('devices.show',$device->id) }}" title="{{ __('crud.show') }}"><i class="fa fa-eye"></i></a> -->
							@permission('sensor-edit')
							<a class="btn btn-primary" href="{{ route('devices.edit',$device->id) }}" title="{{ __('crud.edit') }}"><i class="fa fa-pencil"></i></a>
							@endpermission
							@permission('sensor-delete')
							{!! Form::open(['method' => 'DELETE','route' => ['devices.destroy', $device->id], 'style'=>'display:inline', 'onsubmit'=>'return confirm("'.__('crud.sure',['item'=>__('general.sensor'),'name'=>'\''.$device->name.'\'']).'")']) !!}
				            {!! Form::button('<i class="fa fa-trash-o"></i>', ['type'=>'submit', 'class' => 'btn btn-danger pull-right']) !!}
				        	{!! Form::close() !!}
				        	@endpermission
						</td>
					</tr>
					@endforeach
				</tbody>
			</table>
		@endslot
	@endcomponent
@endsection