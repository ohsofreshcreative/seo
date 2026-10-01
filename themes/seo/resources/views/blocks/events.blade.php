<!--- events --->

@php($hasImages = !empty($g_events['image1']) || !empty($g_events['image2']) || !empty($g_events['image3']))

<section
	data-gsap-anim="section"
	@if(!empty($section_id)) id="{{ $section_id }}" @endif
	@class([ 'b-events relative -smt' ,
	'!pt-0' => $hasImages,
	$sectionClass=> filled($sectionClass),
	$section_class => filled($section_class),
	$background => filled($background) && $background !== 'none',
	])>

	<div class="__wrapper c-main grid">
		@if ($hasImages)
		<div data-gsap-element="img" class="__photos relative z-10 grid grid-cols-3 gap-4 -mt-12 md:-mt-24 mb-14">
			@if (!empty($g_events['image1']))
			<figure class="m-0">
				<img class="radius-img w-full h-72 object-cover" src="{{ $g_events['image1']['url'] }}" alt="{{ $g_events['image1']['alt'] ?? '' }}">
			</figure>
			@endif
			@if (!empty($g_events['image2']))
			<figure class="m-0">
				<img class="radius-img w-full h-72 object-cover" src="{{ $g_events['image2']['url'] }}" alt="{{ $g_events['image2']['alt'] ?? '' }}">
			</figure>
			@endif
			@if (!empty($g_events['image3']))
			<figure class="m-0">
				<img class="radius-img w-full h-72 object-cover" src="{{ $g_events['image3']['url'] }}" alt="{{ $g_events['image3']['alt'] ?? '' }}">
			</figure>
			@endif
		</div>
		@endif

		<div class="__col grid grid-cols-1 lg:grid-cols-2 gap-8 lg:gap-20 items-start">
			<div class="__content">
				@if (!empty($g_events['header']))
				<p data-gsap-element="header" class="text-h4 text-white m-header">{{ $g_events['header'] }}</p>
				@endif
				@if (!empty($g_events['text']))
				<div data-gsap-element="txt" class="text-white mt-3">
					{!! $g_events['text'] !!}
				</div>
				@endif
				@if (!empty($g_events['button']))
				<x-button
					:href="$g_events['button']['url']"
					variant="primary"
					class="m-btn"
					data-gsap-element="btn">
					{{ $g_events['button']['title'] }}
				</x-button>
				@endif
			</div>

			@if (!empty($r_events))
			<div data-gsap-element="stagger" class="__cards flex flex-col gap-4">
				@foreach ($r_events as $item)
				<div class="__card bg-primary-50 radius p-6">
					@if (!empty($item['title']))
					<p class="text-h6 text-primary">{{ $item['title'] }}</p>
					@endif
					@if (!empty($item['text']))
					<p class="text-primary-900 mt-2">{{ $item['text'] }}</p>
					@endif
				</div>
				@endforeach
			</div>
			@endif
		</div>
	</div>
</section>
