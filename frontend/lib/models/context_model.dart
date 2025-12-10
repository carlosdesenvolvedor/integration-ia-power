class ContextModel {
  final int id;
  final String name;
  final String? description;
  final Map<String, dynamic>? content;
  final bool isDefault;

  ContextModel({
    required this.id,
    required this.name,
    this.description,
    this.content,
    this.isDefault = false,
  });

  factory ContextModel.fromJson(Map<String, dynamic> json) {
    return ContextModel(
      id: json['id'],
      name: json['name'],
      description: json['description'],
      content: json['content'],
      isDefault: json['is_default'] == true || json['is_default'] == 1,
    );
  }
}

