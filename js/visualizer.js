(function () {
	const data = JSON.parse(document.getElementById('tree-data').textContent)

	const width = window.innerWidth - 40
	const height = window.innerHeight;

	const svg = d3.select("#tree-container")
		.append("svg")
		.attr("width", width)
		.attr("height", height)
		.append("g")
		.attr("transform", "translate(80,0)")

	const zoom = d3.zoom()
		.scaleExtent([0.1, 3])
		.on("zoom", (event) => {
			svg.attr("transform", event.transform)
		})

	d3.select("svg").call(zoom)

	const tree = d3.tree().size([height, width - 160])

	const root = d3.hierarchy(data[0])
	tree(root)

	const link = svg.selectAll(".link")
		.data(root.links())
		.enter().append("path")
		.attr("class", "link")
		.attr("d", d3.linkHorizontal()
			.x(d => d.y)
			.y(d => d.x))

	const node = svg.selectAll(".node")
		.data(root.descendants())
		.enter().append("g")
		.attr("class", "node")
		.attr("transform", d => `translate(${d.y},${d.x})`)

	node.append("circle")
		.attr("r", 4.5)
		.attr("class", d => d.data.type)

	node.append("text")
		.attr("dy", ".31em")
		.attr("x", d => d.children ? -8 : 8)
		.style("text-anchor", d => d.children ? "end" : "start")
		.text(d => `${d.data.name} (${d.data.percentage}%)`)
})()
